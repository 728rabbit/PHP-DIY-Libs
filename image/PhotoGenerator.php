<?php
/**
 * PhotoGenerator - A GD-based image manipulation class
 * 
 * Supports chainable operations: resize, crop, rotate, watermark, and output
 * 
 * // Basic usage with chaining
 * $img = new PhotoGenerator('input.jpg', 200, 200);
 * $img->resize(400, 300, true)          // Resize keeping aspect ratio
 *     ->crop(200, 200)                   // Crop from center
 *     ->rotate(90)                       // Rotate 90 degrees clockwise
 *     ->addTextWatermark(                // Add text watermark
 *         'Sample Text',
 *         30,
 *         [255, 255, 255],
 *         70,
 *         null,
 *         null,
 *         20
 *     )
 *     ->save('output.jpg');              // Save to file
 * 
 * // Get Base64 data URI
 * $base64 = $img->getBase64('png');
 * echo $base64;
 * 
 * // Create blank placeholder
 * $placeholder = new PhotoGenerator(null, 400, 300);
 * $placeholder->save('placeholder.png');
 * 
 * // Image watermark
 * $img->addImageWatermark('logo.png', 50, null, null, 15)
 *     ->save('watermarked.jpg');
 */
namespace App\Libs\Image;

class PhotoGenerator
{
    /** @var resource|null GD image resource */
    private $image = null;
    
    /** @var int Current image width */
    private $width = 0;
    
    /** @var int Current image height */
    private $height = 0;
    
    /** @var int Image type constant (IMAGETYPE_*) */
    private $type = null;
    
    /** @var int Quality for JPEG/WEBP (0-100) */
    private $quality = 90;
    
    /** @var string|null Font path for text watermark */
    private $fontPath = null;
    
    /** @var bool Whether to preserve aspect ratio on resize */
    private $keepAspectRatio = true;
    
    /**
     * Constructor - Load image from file or create blank placeholder
     * 
     * @param string|null $file Path to image file
     * @param int $defaultWidth Default width for blank image
     * @param int $defaultHeight Default height for blank image
     * @param string|null $fontPath Path to TTF/OTF font file
     * @throws \RuntimeException If image format is unsupported or file cannot be loaded
     */
    public function __construct(
        $file = null, 
        $defaultWidth = 200, 
        $defaultHeight = 200,
        $fontPath = null
    ) {
        // Set default font path if not provided
        $this->fontPath = $fontPath ?: __DIR__ . '/NotoSansCJKBold.otf';
        
        if ($file && file_exists($file) && filesize($file) > 0) {
            $this->loadImage($file);
        } else {
            // Create blank placeholder with specified dimensions
            $this->createBlankImage(
                max(1, $defaultWidth), 
                max(1, $defaultHeight)
            );
        }
    }
    
    /**
     * Load image from file
     * 
     * @param string $file Path to image file
     * @throws \RuntimeException If file format is unsupported or corrupted
     */
    private function loadImage($file)
    {
        $info = @getimagesize($file);
        if ($info === false) {
            throw new \RuntimeException("Cannot read image: $file");
        }
        
        list($this->width, $this->height, $this->type) = $info;
        
        // Load image based on type
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $this->image = imagecreatefromjpeg($file);
                break;
            case IMAGETYPE_PNG:
                $this->image = imagecreatefrompng($file);
                break;
            case IMAGETYPE_GIF:
                $this->image = imagecreatefromgif($file);
                break;
            case IMAGETYPE_WBMP:
                $this->image = imagecreatefromwbmp($file);
                break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagecreatefromwebp')) {
                    throw new \RuntimeException("WEBP not supported by your GD installation");
                }
                $this->image = imagecreatefromwebp($file);
                break;
            default:
                throw new \RuntimeException("Unsupported image type: " . $this->type);
        }
        
        if ($this->image === false) {
            throw new \RuntimeException("Failed to create image from: $file");
        }
        
        // Preserve PNG transparency
        if ($this->type === IMAGETYPE_PNG) {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
        }
    }
    
    /**
     * Create a blank placeholder image with dimensions text
     * 
     * @param int $width Width of blank image
     * @param int $height Height of blank image
     */
    private function createBlankImage($width, $height)
    {
        $this->image = imagecreatetruecolor($width, $height);
        $this->width = $width;
        $this->height = $height;
        $this->type = IMAGETYPE_PNG;
        
        // Gray background
        $bgColor = imagecolorallocate($this->image, 200, 200, 200);
        imagefill($this->image, 0, 0, $bgColor);
        
        // White text with dimensions
        $textColor = imagecolorallocate($this->image, 255, 255, 255);
        $fontSize = max(10, min(30, ceil($width * 0.08)));
        $message = $width . 'x' . $height;
        
        // Try to use font, fallback to built-in if not available
        if (file_exists($this->fontPath)) {
            $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $message);
            if ($bbox !== false) {
                $textWidth = $bbox[2] - $bbox[0];
                $textHeight = $bbox[1] - $bbox[7];
                $x = ($width - $textWidth) / 2;
                $y = ($height + $textHeight) / 2;
                imagettftext($this->image, $fontSize, 0, $x, $y, $textColor, $this->fontPath, $message);
                return;
            }
        }
        
        // Fallback: use built-in font
        imagestring($this->image, 5, ($width - strlen($message) * imagefontwidth(5)) / 2, 
                    ($height - imagefontheight(5)) / 2, $message, $textColor);
    }
    
    /**
     * Create a new canvas with proper transparency handling
     * 
     * @param int $width Width of new canvas
     * @param int $height Height of new canvas
     * @return resource GD image resource
     */
    private function createCanvas($width, $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        
        // Handle PNG transparency
        if ($this->type === IMAGETYPE_PNG) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        } else {
            // White background for other formats
            $bgColor = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $bgColor);
        }
        
        return $canvas;
    }
    
    /**
     * Resize image with optional aspect ratio preservation
     * 
     * @param int $newWidth Target width (0 to auto-calculate from height)
     * @param int $newHeight Target height (0 to auto-calculate from width)
     * @param bool $keepAspectRatio Whether to maintain aspect ratio
     * @return self
     * @throws \RuntimeException If image resource is invalid
     */
    public function resize($newWidth = 0, $newHeight = 0, $keepAspectRatio = true)
    {
        if (!$this->image) {
            throw new \RuntimeException("No image to resize");
        }
        
        // Auto-calculate missing dimension
        if ($newWidth <= 0 && $newHeight <= 0) {
            return $this;
        }
        
        if ($keepAspectRatio) {
            $ratio = $this->width / $this->height;
            
            if ($newWidth <= 0) {
                $newWidth = round($newHeight * $ratio);
            } elseif ($newHeight <= 0) {
                $newHeight = round($newWidth / $ratio);
            } else {
                // Both dimensions specified: fit within box while preserving ratio
                $ratioX = $newWidth / $this->width;
                $ratioY = $newHeight / $this->height;
                $finalRatio = min($ratioX, $ratioY);
                $newWidth = round($this->width * $finalRatio);
                $newHeight = round($this->height * $finalRatio);
            }
        } else {
            // Stretch to exact dimensions
            $newWidth = max(1, $newWidth);
            $newHeight = max(1, $newHeight);
        }
        
        // Create new canvas
        $newImage = $this->createCanvas($newWidth, $newHeight);
        
        // Resample with high quality
        imagecopyresampled(
            $newImage, $this->image,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $this->width, $this->height
        );
        
        // Replace old image
        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $newWidth;
        $this->height = $newHeight;
        
        return $this;
    }
    
    /**
     * Crop image with optional center positioning
     * 
     * @param int $cropWidth Width of crop area
     * @param int $cropHeight Height of crop area
     * @param int|null $x X offset from left (null for center)
     * @param int|null $y Y offset from top (null for center)
     * @return self
     * @throws \RuntimeException If image resource is invalid
     */
    public function crop($cropWidth, $cropHeight, $x = null, $y = null)
    {
        if (!$this->image) {
            throw new \RuntimeException("No image to crop");
        }
        
        // Clamp dimensions
        $cropWidth = min(max(1, $cropWidth), $this->width);
        $cropHeight = min(max(1, $cropHeight), $this->height);
        
        // Calculate center offset if not specified
        if ($x === null) {
            $x = round(($this->width - $cropWidth) / 2);
        }
        if ($y === null) {
            $y = round(($this->height - $cropHeight) / 2);
        }
        
        // Clamp coordinates
        $x = max(0, min($x, $this->width - 1));
        $y = max(0, min($y, $this->height - 1));
        
        // Ensure we don't exceed image bounds
        $srcWidth = min($cropWidth, $this->width - $x);
        $srcHeight = min($cropHeight, $this->height - $y);
        
        // Create new canvas
        $newImage = $this->createCanvas($cropWidth, $cropHeight);
        
        // Copy cropped portion
        imagecopyresampled(
            $newImage, $this->image,
            0, 0, $x, $y,
            $cropWidth, $cropHeight,
            $srcWidth, $srcHeight
        );
        
        // Replace old image
        imagedestroy($this->image);
        $this->image = $newImage;
        $this->width = $cropWidth;
        $this->height = $cropHeight;
        
        return $this;
    }
    
    /**
     * Rotate image by angle (in degrees)
     * 
     * @param int $angle Rotation angle in degrees (clockwise)
     * @param int $bgColor Background color for empty spaces (0 = transparent for PNG)
     * @return self
     * @throws \RuntimeException If rotation fails
     */
    public function rotate($angle, $bgColor = 0)
    {
        if (!$this->image) {
            throw new \RuntimeException("No image to rotate");
        }
        
        // Normalize angle
        $angle = fmod($angle, 360);
        if ($angle < 0) {
            $angle += 360;
        }
        
        $rotated = imagerotate($this->image, $angle, $bgColor);
        if ($rotated === false) {
            throw new \RuntimeException("Image rotation failed");
        }
        
        // Preserve PNG transparency
        if ($this->type === IMAGETYPE_PNG) {
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
        }
        
        imagedestroy($this->image);
        $this->image = $rotated;
        $this->width = imagesx($rotated);
        $this->height = imagesy($rotated);
        
        return $this;
    }
    
    /**
     * Add text watermark
     * 
     * @param string $text Watermark text
     * @param int $fontSize Font size in points
     * @param array $color RGB color array [r, g, b]
     * @param int $opacity Opacity percentage (0-100, 0 = fully transparent)
     * @param int|null $x X position (null for right-aligned with margin)
     * @param int|null $y Y position (null for bottom-aligned with margin)
     * @param int $margin Margin from edges when x/y is null
     * @return self
     * @throws \RuntimeException If font file not found
     */
    public function addTextWatermark(
        $text,
        $fontSize = 20,
        $color = [255, 255, 255],
        $opacity = 50,
        $x = null,
        $y = null,
        $margin = 20
    ) {
        if (!$this->image) {
            throw new \RuntimeException("No image to watermark");
        }
        
        if (!file_exists($this->fontPath)) {
            throw new \RuntimeException("Font file not found: " . $this->fontPath);
        }
        
        list($r, $g, $b) = $color;
        $alpha = 127 - round(127 * ($opacity / 100));
        $textColor = imagecolorallocatealpha($this->image, $r, $g, $b, max(0, min(127, $alpha)));
        
        // Calculate text dimensions
        $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $text);
        if ($bbox === false) {
            throw new \RuntimeException("Failed to calculate text bounds");
        }
        
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        
        // Auto-position
        if ($x === null) {
            $x = $this->width - $textWidth - $margin;
        }
        if ($y === null) {
            $y = $this->height - $margin;
        }
        
        // Draw text
        imagettftext($this->image, $fontSize, 0, $x, $y, $textColor, $this->fontPath, $text);
        
        return $this;
    }
    
    /**
     * Add image watermark (PNG only)
     * 
     * @param string $watermarkPath Path to PNG image
     * @param int $opacity Opacity percentage (0-100)
     * @param int|null $x X position (null for right-aligned with margin)
     * @param int|null $y Y position (null for bottom-aligned with margin)
     * @param int $margin Margin from edges when x/y is null
     * @return self
     * @throws \RuntimeException If watermark image cannot be loaded
     */
    public function addImageWatermark(
        $watermarkPath,
        $opacity = 50,
        $x = null,
        $y = null,
        $margin = 10
    ) {
        if (!$this->image) {
            throw new \RuntimeException("No image to watermark");
        }
        
        if (!file_exists($watermarkPath)) {
            throw new \RuntimeException("Watermark image not found: $watermarkPath");
        }
        
        // Load watermark
        $watermark = imagecreatefrompng($watermarkPath);
        if ($watermark === false) {
            throw new \RuntimeException("Failed to load watermark image (must be PNG)");
        }
        
        $wmWidth = imagesx($watermark);
        $wmHeight = imagesy($watermark);
        
        // Auto-position
        if ($x === null) {
            $x = $this->width - $wmWidth - $margin;
        }
        if ($y === null) {
            $y = $this->height - $wmHeight - $margin;
        }
        
        // Apply opacity using imagecopymerge for true alpha blending
        // Convert opacity (0-100) to transparency level for imagecopymerge (0-100)
        $transparency = max(0, min(100, 100 - $opacity));
        
        // Enable alpha blending for watermark
        imagealphablending($watermark, true);
        imagesavealpha($watermark, true);
        
        // Merge with transparency
        imagecopymerge(
            $this->image,
            $watermark,
            $x, $y,
            0, 0,
            $wmWidth, $wmHeight,
            $transparency
        );
        
        imagedestroy($watermark);
        return $this;
    }
    
    /**
     * Legacy method: Add watermark (text or image)
     * 
     * @deprecated Use addTextWatermark() or addImageWatermark() instead
     * @param string $watermark Watermark text or image path
     * @param string $type 'text' or 'image'
     * @param int $fontSize Font size (text only)
     * @param array $fontColor RGB color array (text only)
     * @param int $opacity Opacity percentage
     * @param int $x X position
     * @param int $y Y position
     * @return self
     */
    public function addWatermark(
        $watermark,
        $type = 'text',
        $fontSize = 20,
        $fontColor = [255, 255, 255],
        $opacity = 50,
        $x = 0,
        $y = 0
    ) {
        if ($type === 'text') {
            return $this->addTextWatermark($watermark, $fontSize, $fontColor, $opacity, $x, $y);
        } elseif ($type === 'image') {
            return $this->addImageWatermark($watermark, $opacity, $x, $y);
        } else {
            throw new \InvalidArgumentException("Invalid watermark type. Use 'text' or 'image'.");
        }
    }
    
    /**
     * Set JPEG/WEBP quality
     * 
     * @param int $quality Quality value (0-100)
     * @return self
     */
    public function setQuality($quality)
    {
        $this->quality = max(0, min(100, (int)$quality));
        return $this;
    }
    
    /**
     * Set font path for text watermarking
     * 
     * @param string $fontPath Path to TTF/OTF font file
     * @return self
     */
    public function setFont($fontPath)
    {
        if (file_exists($fontPath)) {
            $this->fontPath = $fontPath;
        }
        return $this;
    }
    
    /**
     * Save image to file
     * 
     * @param string $outputFile Output file path
     * @return string Output file path on success
     * @throws \RuntimeException If save fails
     */
    public function save($outputFile)
    {
        if (!$this->image) {
            throw new \RuntimeException("No image to save");
        }
        
        // Create output directory if needed
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \RuntimeException("Cannot create output directory: $outputDir");
            }
        }
        
        // Determine output format from extension
        $ext = strtolower(pathinfo($outputFile, PATHINFO_EXTENSION));
        $format = $this->getFormatFromExtension($ext);
        
        // If no extension, use original image type
        if ($format === null) {
            $format = $this->type;
        }
        
        // Save based on format
        $success = false;
        switch ($format) {
            case IMAGETYPE_JPEG:
                $success = imagejpeg($this->image, $outputFile, $this->quality);
                break;
            case IMAGETYPE_PNG:
                $pngQuality = (int)(9 * (100 - $this->quality) / 100);
                $success = imagepng($this->image, $outputFile, max(0, min(9, $pngQuality)));
                break;
            case IMAGETYPE_GIF:
                $success = imagegif($this->image, $outputFile);
                break;
            case IMAGETYPE_WBMP:
                $success = imagewbmp($this->image, $outputFile);
                break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagewebp')) {
                    throw new \RuntimeException("WEBP not supported by your GD installation");
                }
                $success = imagewebp($this->image, $outputFile, $this->quality);
                break;
            default:
                throw new \RuntimeException("Unsupported output format: " . $ext);
        }
        
        if (!$success) {
            throw new \RuntimeException("Failed to save image: $outputFile");
        }
        
        return $outputFile;
    }
    
    /**
     * Get image as Base64 data URI
     * 
     * @param string $format Output format (png, jpeg, gif, webp)
     * @return string Base64 data URI
     * @throws \RuntimeException If output fails
     */
    public function getBase64($format = 'png')
    {
        if (!$this->image) {
            throw new \RuntimeException("No image to encode");
        }
        
        // Map format string to type constant
        $formatMap = [
            'png' => IMAGETYPE_PNG,
            'jpeg' => IMAGETYPE_JPEG,
            'jpg' => IMAGETYPE_JPEG,
            'gif' => IMAGETYPE_GIF,
            'webp' => IMAGETYPE_WEBP,
        ];
        
        $type = isset($formatMap[$format]) ? $formatMap[$format] : $this->type;
        
        ob_start();
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($this->image, null, $this->quality);
                $mime = 'jpeg';
                break;
            case IMAGETYPE_PNG:
                imagepng($this->image);
                $mime = 'png';
                break;
            case IMAGETYPE_GIF:
                imagegif($this->image);
                $mime = 'gif';
                break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagewebp')) {
                    ob_end_clean();
                    throw new \RuntimeException("WEBP not supported by your GD installation");
                }
                imagewebp($this->image, null, $this->quality);
                $mime = 'webp';
                break;
            default:
                ob_end_clean();
                throw new \RuntimeException("Unsupported format for Base64 output");
        }
        
        $data = ob_get_contents();
        ob_end_clean();
        
        return 'data:image/' . $mime . ';base64,' . base64_encode($data);
    }
    
    /**
     * Get image resource (for advanced operations)
     * 
     * @return resource GD image resource
     */
    public function getImageResource()
    {
        return $this->image;
    }
    
    /**
     * Get current image dimensions
     * 
     * @return array ['width' => int, 'height' => int]
     */
    public function getDimensions()
    {
        return ['width' => $this->width, 'height' => $this->height];
    }
    
    /**
     * Get current image type
     * 
     * @return int IMAGETYPE_* constant
     */
    public function getType()
    {
        return $this->type;
    }
    
    /**
     * Determine format from file extension
     * 
     * @param string $ext File extension
     * @return int|null IMAGETYPE_* constant or null if not recognized
     */
    private function getFormatFromExtension($ext)
    {
        $map = [
            'jpg' => IMAGETYPE_JPEG,
            'jpeg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'gif' => IMAGETYPE_GIF,
            'wbmp' => IMAGETYPE_WBMP,
            'webp' => IMAGETYPE_WEBP,
        ];
        
        return isset($map[$ext]) ? $map[$ext] : null;
    }
    
    /**
     * Destructor - free image memory
     */
    public function __destruct()
    {
        if ($this->image && is_resource($this->image)) {
            imagedestroy($this->image);
        }
    }
}