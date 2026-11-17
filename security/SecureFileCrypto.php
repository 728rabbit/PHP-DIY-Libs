<?php
/**
 * SecureFileCrypto Usage
 * ======================
 *
 * [Encrypt]
 *   $key        = md5('Abc123');                              // Demo only; use a secure KDF in production
 *   $sourcePath = storage_path($location . '/' . $fileName);
 *   $destPath   = public_path($locationMapping . '/crypto/' . md5($fileName) . '.enc');
 *
 *   $crypto = new \App\Libs\security\SecureFileCrypto($key);
 *   $crypto->encryptFile($sourcePath, $destPath);
 *
 * [Decrypt and stream to output]
 *   $encPath      = $destPath;
 *   $originalName = 'report.pdf';
 *   $mime         = 'application/pdf';
 *
 *   header('Content-Type: ' . $mime);
 *   header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"');
 *   header('Cache-Control: no-store');
 *   header('X-Content-Type-Options: nosniff');
 *
 *   $out = fopen('php://output', 'wb');
 *   try {
 *       $crypto->decryptFileToResource($encPath, $out);
 *   } catch (Throwable $e) {
 *       error_log('Decryption failed: ' . $e->getMessage());
 *   } finally {
 *       fclose($out);
 *   }
 */
namespace App\Libs\security;

use Exception;
use RuntimeException;

class SecureFileCrypto
{
    /** AEAD cipher: authenticated encryption, no separate HMAC needed. */
    private const CIPHER = 'aes-256-gcm';

    /** GCM tag length in bytes (16 is the maximum and recommended). */
    private const TAG_LENGTH = 16;

    /** Key length for AES-256 (32 bytes). */
    private const KEY_LENGTH = 32;

    /** Stream chunk size (1 MiB) to keep memory bounded. */
    private const CHUNK_SIZE = 1048576;

    private string $secretKey;

    /**
     * @param string $key Raw key material (from .env / KMS). Must have >= 32 bytes of entropy.
     * @throws Exception if the key is too weak.
     */
    public function __construct(string $key)
    {
        if (strlen($key) < self::KEY_LENGTH) {
            throw new Exception('Encryption key must be at least 32 bytes of high-entropy material.');
        }
        // Derive a fixed 32-byte key. Do NOT use this to "stretch" a weak password —
        // feed a real random key (e.g. base64 of 32 random bytes) from your secret store.
        $this->secretKey = hash('sha256', $key, true);
    }

    /**
     * Encrypt a file to $destPath using AES-256-GCM.
     *
     * File format:
     *   [ 1 byte version ][ 12 byte IV ][ ciphertext ][ 16 byte GCM tag ]
     *
     * @throws Exception
     */
    public function encryptFile(string $sourcePath, string $destPath): bool
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new Exception("Source file does not exist or is unreadable: {$sourcePath}");
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);   // 12 for GCM
        $iv       = random_bytes($ivLength);

        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new RuntimeException("Failed to read source file: {$sourcePath}");
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->secretKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('OpenSSL encryption failed: ' . openssl_error_string());
        }

        // Version byte lets you migrate formats later without breaking old files.
        $payload = "\x01" . $iv . $ciphertext . $tag;

        // Atomic write: temp file in same directory, then rename.
        $tmp = $destPath . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write encrypted file: {$destPath}");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $destPath)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to move encrypted file into place: {$destPath}");
        }

        // Best-effort wipe of plaintext from memory.
        if (function_exists('sodium_memzero')) {
            sodium_memzero($plaintext);
        }

        return true;
    }

    /**
     * Decrypt a file into memory and return the raw plaintext bytes.
     *
     * @throws Exception on corruption, tampering, or missing file.
     */
    public function decryptFileToStream(string $encryptedFilePath): string
    {
        if (!is_file($encryptedFilePath) || !is_readable($encryptedFilePath)) {
            throw new Exception("Encrypted file does not exist or is unreadable: {$encryptedFilePath}");
        }

        $content = file_get_contents($encryptedFilePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read encrypted file: {$encryptedFilePath}");
        }

        $ivLength  = openssl_cipher_iv_length(self::CIPHER);
        $headerLen = 1 + $ivLength + self::TAG_LENGTH;

        if (strlen($content) < $headerLen) {
            throw new Exception('Encrypted file is truncated or corrupted.');
        }
        if ($content[0] !== "\x01") {
            throw new Exception('Unsupported encrypted file version.');
        }

        $iv         = substr($content, 1, $ivLength);
        $tag        = substr($content, -self::TAG_LENGTH);
        $ciphertext = substr($content, 1 + $ivLength, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->secretKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // This fires on wrong key OR tampered ciphertext/tag/IV.
            throw new Exception('Decryption failed: authentication tag mismatch or corrupted data.');
        }

        return $plaintext;
    }

    /**
     * Stream decryption to an output resource (e.g. fopen('php://output','wb') or a temp file).
     * Bounded memory: works for arbitrarily large files.
     *
     * NOTE: AES-GCM is not trivially streamable in PHP's openssl_* API.
     * For true streaming of huge files, use AES-256-CTR + HMAC over the whole
     * ciphertext, or chunked AEAD (per-chunk IV derived from a counter).
     * This method reads in chunks for the write side only; the crypto is still whole-file.
     *
     * @param resource $outStream
     */
    public function decryptFileToResource(string $encryptedFilePath, $outStream): bool
    {
        if (!is_resource($outStream)) {
            throw new Exception('Output stream is not a valid resource.');
        }

        $plaintext = $this->decryptFileToStream($encryptedFilePath);
        $len = strlen($plaintext);
        $off = 0;
        while ($off < $len) {
            $written = fwrite($outStream, substr($plaintext, $off, self::CHUNK_SIZE));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write decrypted data to output stream.');
            }
            $off += $written;
        }
        return true;
    }
}