<?php
/**
 * HTML Sanitizer with recursive array support, tag/attribute whitelist,
 * style filtering, and iframe domain restriction.
 *
 * Features:
 * - Recursively sanitizes nested arrays
 * - Strict and relaxed modes
 * - DOM-based parsing (not regex)
 * - Inline style filtering
 * - Iframe sandboxing with domain whitelist
 */
namespace App\Libs\security;

class HtmlSanitizer
{
    private bool $strictMode;
    private array $customTags;
    private array $customAttrs;

    /**
     * Whitelisted tags for strict mode (text-only formatting)
     */
    private const STRICT_TAGS = [
        'p', 'br', 'div',
        'span', 'b', 'strong', 'i', 'em', 'u', 'strike',
        'sub', 'sup',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'hr'
    ];

    /**
     * Whitelisted tags for relaxed mode (includes media and tables)
     */
    private const RELAXED_TAGS = [
        'p', 'br', 'div',
        'span', 'b', 'strong', 'i', 'em', 'u', 'strike',
        'sub', 'sup',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'a', 'img', 'iframe', 'video', 'audio', 'source'
    ];

    /**
     * Allowed inline style properties
     */
    private const ALLOWED_STYLES = [
        'color', 'background-color',
        'font-size', 'font-weight', 'font-style',
        'text-align', 'text-decoration', 'line-height',
        'display', 'vertical-align', 'white-space', 'border-collapse',
        'width', 'height', 'max-width', 'max-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border',
        'border-width', 'border-top-width', 'border-right-width',
        'border-bottom-width', 'border-left-width',
        'border-style', 'border-top-style', 'border-right-style',
        'border-bottom-style', 'border-left-style',
        'border-color', 'border-top-color', 'border-right-color',
        'border-bottom-color', 'border-left-color',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius',
        'border-bottom-right-radius', 'border-bottom-left-radius',
        'background', 'background-position', 'background-size', 'background-repeat',
    ];

    /**
     * Allowed iframe domains
     */
    private const ALLOWED_IFRAME_DOMAINS = [
        'youtube.com',
        'www.youtube.com',
        'facebook.com',
        'www.facebook.com',
        'google.com',
        'www.google.com',
        'player.vimeo.com'
    ];

    /**
     * Constructor
     *
     * @param bool $strictMode Enable strict mode (removes href, src, style, etc.)
     * @param array $customTags Additional tags to allow (merged with default whitelist)
     * @param array $customAttrs Additional attributes to allow (merged with default whitelist)
     */
    public function __construct(
        bool $strictMode = true,
        array $customTags = [],
        array $customAttrs = []
    ) {
        $this->strictMode = $strictMode;
        $this->customTags = $customTags;
        $this->customAttrs = $customAttrs;
    }

    /* =========================================================
     * PUBLIC ENTRY POINT
     * ========================================================= */

    /**
     * Sanitize input (string or nested array)
     *
     * @param string|array $input
     * @return string|array
     */
    public function sanitize($input)
    {
        return $this->sanitizeRecursive($input);
    }

    /* =========================================================
     * RECURSIVE SANITIZER
     * ========================================================= */

    /**
     * Recursively sanitize nested arrays
     *
     * @param string|array $input
     * @return string|array
     */
    private function sanitizeRecursive($input)
    {
        if (is_array($input)) {
            $result = [];
            foreach ($input as $key => $value) {
                $result[$key] = $this->sanitizeRecursive($value);
            }
            return $result;
        }

        return $this->sanitizeOne((string) $input);
    }

    /* =========================================================
     * CORE SANITIZER (DOM-based)
     * ========================================================= */

    /**
     * Sanitize a single HTML string using DOMDocument
     *
     * @param string $html
     * @return string
     */
    private function sanitizeOne(string $html): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Wrap content to preserve structure and avoid body/tag stripping
        $wrapped = '<div id="sanitizer-root">' . $html . '</div>';

        $dom->loadHTML(
            mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $root = $dom->getElementById('sanitizer-root');

        if (!$root) {
            libxml_clear_errors();
            return '';
        }

        $this->walk($root);

        // Extract sanitized content (skip the wrapper div)
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        libxml_clear_errors();

        return $output;
    }

    /* =========================================================
     * DOM TRAVERSAL
     * ========================================================= */

    /**
     * Recursively walk DOM tree and apply sanitization
     *
     * @param \DOMNode $node
     * @return void
     */
    private function walk(\DOMNode $node): void
    {
        // Encode text nodes to prevent XSS
        if ($node->nodeType === XML_TEXT_NODE) {
            $node->nodeValue = htmlspecialchars($node->nodeValue, ENT_QUOTES, 'UTF-8');
            return;
        }

        // Only process element nodes
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        /** @var \DOMElement $node */
        $tag = strtolower($node->nodeName);

        // Check tag whitelist
        if (!$this->isAllowedTag($tag)) {
            $this->unwrap($node);
            return;
        }

        // Sanitize attributes
        $this->sanitizeAttributes($node, $tag);

        // Process children
        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }

            foreach ($children as $child) {
                $this->walk($child);
            }
        }
    }

    /* =========================================================
     * TAG POLICY
     * ========================================================= */

    /**
     * Check if tag is allowed
     *
     * @param string $tag
     * @return bool
     */
    private function isAllowedTag(string $tag): bool
    {
        $whitelist = $this->strictMode
            ? self::STRICT_TAGS
            : self::RELAXED_TAGS;

        // Merge custom tags
        if (!empty($this->customTags)) {
            $whitelist = array_merge($whitelist, $this->customTags);
        }

        return in_array($tag, $whitelist, true);
    }

    /* =========================================================
     * ATTRIBUTE SANITIZATION
     * ========================================================= */

    /**
     * Sanitize attributes of an element
     *
     * @param \DOMElement $node
     * @param string $tag
     * @return void
     */
    private function sanitizeAttributes(\DOMElement $node, string $tag): void
    {
        if (!$node->hasAttributes()) {
            return;
        }

        $allowed = $this->getAllowedAttributes($tag);

        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue;

            // Remove event handlers (onclick, onload, etc.)
            if (str_starts_with($name, 'on')) {
                $node->removeAttribute($name);
                continue;
            }

            // Block javascript: protocol (with encoding protection)
            if ($this->containsJsProtocol($value)) {
                $node->removeAttribute($name);
                continue;
            }

            // Block data: URI for images (prevents SVG XSS)
            if ($tag === 'img' && $name === 'src' && $this->isDangerousDataUri($value)) {
                $node->removeAttribute($name);
                continue;
            }

            // Check attribute whitelist
            if (!in_array($name, $allowed, true)) {
                $node->removeAttribute($name);
                continue;
            }

            // Sanitize style attribute
            if ($name === 'style') {
                $clean = $this->sanitizeStyle($value);
                if ($clean === '') {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', $clean);
                }
            }
        }

        // Additional iframe security
        if ($tag === 'iframe') {
            $this->sanitizeIframe($node);
        }
    }

    /* =========================================================
     * ATTRIBUTE POLICY
     * ========================================================= */

    /**
     * Get allowed attributes for a specific tag
     *
     * @param string $tag
     * @return array
     */
    private function getAllowedAttributes(string $tag): array
    {
        $common = ['style', 'class', 'title', 'id'];

        $attributeMap = [
            'a'       => ['href', 'target', 'rel', 'title'],
            'iframe'  => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder'],
            'img'     => ['src', 'alt', 'width', 'height', 'title'],
            'video'   => ['src', 'controls', 'width', 'height', 'autoplay', 'loop', 'muted'],
            'audio'   => ['src', 'controls', 'autoplay', 'loop'],
            'source'  => ['src', 'type', 'media'],
            'table'   => ['width', 'border', 'cellpadding', 'cellspacing'],
            'td'      => ['colspan', 'rowspan', 'width', 'align', 'valign'],
            'th'      => ['colspan', 'rowspan', 'width', 'align', 'valign', 'scope'],
            'tr'      => ['align', 'valign'],
            'thead'   => ['align', 'valign'],
            'tbody'   => ['align', 'valign'],
            'tfoot'   => ['align', 'valign'],
        ];

        $specific = $attributeMap[$tag] ?? [];

        // Merge custom attributes
        if (!empty($this->customAttrs)) {
            $specific = array_merge($specific, $this->customAttrs);
        }

        if ($this->strictMode) {
            // Strict mode: remove dangerous attributes
            $dangerous = ['style', 'href', 'src', 'allow', 'allowfullscreen'];
            return array_values(array_diff(
                array_merge($common, $specific),
                $dangerous
            ));
        }

        return array_values(array_merge($common, $specific));
    }

    /* =========================================================
     * JAVASCRIPT PROTOCOL DETECTION
     * ========================================================= */

    /**
     * Detect javascript: protocol (with encoding bypass protection)
     *
     * @param string $value
     * @return bool
     */
    private function containsJsProtocol(string $value): bool
    {
        // Decode URL-encoded characters
        $decoded = rawurldecode($value);

        // Decode common HTML entities (basic protection)
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Pattern: case-insensitive, allows whitespace between characters
        // Matches: javascript:, j a v a s c r i p t :, etc.
        $pattern = '/\s*j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i';

        return preg_match($pattern, $decoded) === 1;
    }

    /* =========================================================
     * DATA URI DETECTION
     * ========================================================= */

    /**
     * Check if data URI is dangerous (SVG with embedded scripts)
     *
     * @param string $uri
     * @return bool
     */
    private function isDangerousDataUri(string $uri): bool
    {
        // Only check data: URIs
        if (!str_starts_with($uri, 'data:')) {
            return false;
        }

        // Block SVG images which can contain scripts
        if (preg_match('/^data:image\/svg\+xml/i', $uri)) {
            return true;
        }

        // Block any data URI containing script or javascript
        if ($this->containsJsProtocol($uri)) {
            return true;
        }

        return false;
    }

    /* =========================================================
     * INLINE STYLE SANITIZER
     * ========================================================= */

    /**
     * Sanitize inline style attribute
     *
     * @param string $style
     * @return string
     */
    private function sanitizeStyle(string $style): string
    {
        $parts = explode(';', $style);
        $clean = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!str_contains($part, ':')) {
                continue;
            }

            [$prop, $val] = array_map('trim', explode(':', $part, 2));

            $prop = strtolower($prop);

            // Block dangerous CSS patterns
            if ($this->hasDangerousCssPattern($val)) {
                continue;
            }

            // Check property whitelist
            if (in_array($prop, self::ALLOWED_STYLES, true)) {
                // Encode value to prevent XSS
                $clean[] = $prop . ':' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            }
        }

        return implode('; ', $clean);
    }

    /**
     * Check for dangerous CSS patterns
     *
     * @param string $value
     * @return bool
     */
    private function hasDangerousCssPattern(string $value): bool
    {
        $patterns = [
            '/expression\s*\(/i',
            '/javascript\s*:/i',
            '/url\s*\(\s*["\']?\s*javascript/i',
            '/@import/i',
            '/behavior\s*:/i',
            '/binding\s*:/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /* =========================================================
     * IFRAME SECURITY
     * ========================================================= */

    /**
     * Sanitize iframe: enforce domain whitelist and add sandbox
     *
     * @param \DOMElement $node
     * @return void
     */
    private function sanitizeIframe(\DOMElement $node): void
    {
        $src = $node->getAttribute('src');

        // Validate domain using parse_url (more reliable than str_contains)
        $host = parse_url($src, PHP_URL_HOST);

        if (!$host) {
            $node->parentNode->removeChild($node);
            return;
        }

        // Check if domain is allowed
        $isAllowed = false;
        foreach (self::ALLOWED_IFRAME_DOMAINS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $node->parentNode->removeChild($node);
            return;
        }

        // Enforce sandbox for security
        $node->setAttribute('sandbox', 'allow-scripts allow-same-origin');
        $node->setAttribute('frameborder', '0');

        // Remove dangerous allow attribute if not needed
        $allow = $node->getAttribute('allow');
        if ($allow) {
            // Only keep safe allow values
            $safeAllow = ['autoplay', 'encrypted-media', 'fullscreen'];
            $parsed = explode(';', $allow);
            $filtered = array_filter($parsed, function ($item) use ($safeAllow) {
                $trimmed = trim($item);
                foreach ($safeAllow as $safe) {
                    if (str_starts_with($trimmed, $safe)) {
                        return true;
                    }
                }
                return false;
            });
            if (empty($filtered)) {
                $node->removeAttribute('allow');
            } else {
                $node->setAttribute('allow', implode('; ', $filtered));
            }
        }
    }

    /* =========================================================
     * UNWRAP INVALID NODE
     * ========================================================= */

    /**
     * Unwrap a node (replace with its children) when tag is not allowed
     *
     * @param \DOMNode $node
     * @return void
     */
    private function unwrap(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}