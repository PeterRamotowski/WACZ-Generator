<?php

namespace App\Service;

class ContentTypeService
{
    /**
     * Check if content type represents text content
     */
    public function isTextContent(?string $contentType): bool
    {
        if (!$contentType) {
            return false;
        }

        $textTypes = [
            'text/html',
            'text/plain',
            'text/xml',
            'text/css',
            'application/xml',
            'application/xhtml+xml',
            'application/json',
            'application/ld+json',
            'application/javascript',
            'text/javascript'
        ];

        $lowerContentType = strtolower($contentType);
        foreach ($textTypes as $type) {
            if (str_contains($lowerContentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if content type represents HTML content
     */
    public function isHtmlContent(?string $contentType): bool
    {
        if (!$contentType) {
            return false;
        }

        return str_contains(strtolower($contentType), 'text/html') || 
               str_contains(strtolower($contentType), 'application/xhtml');
    }

    /**
     * Extract text content from HTML
     */
    public function extractTextFromHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $this->sanitizeForJson($text, 10000);
    }

    /**
     * Sanitize text content for safe JSON encoding
     */
    public function sanitizeForJson(string $text, int $maxLength = 5000): string
    {
        // Remove control characters except newlines, tabs, and carriage returns
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Ensure proper UTF-8 encoding
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove any remaining invalid UTF-8 sequences
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', '', $text);

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '...';
        }

        $text = trim($text);

        return $text;
    }

    /**
     * Check if content is gzipped
     */
    public function isGzipContent(string $content): bool
    {
        return strlen($content) >= 2 && substr($content, 0, 2) === "\x1f\x8b";
    }
}
