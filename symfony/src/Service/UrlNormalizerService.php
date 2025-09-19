<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class UrlNormalizerService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Normalize URL by removing fragments and standardizing paths
     */
    public function normalizeUrl(string $url): string
    {
        // Early check for invalid protocols - return as-is to be filtered later
        $invalidProtocols = ['javascript:', 'tel:', 'mailto:', 'ftp:', 'file:', 'data:', 'blob:', 'about:'];
        foreach ($invalidProtocols as $protocol) {
            if (str_starts_with(strtolower($url), $protocol)) {
                return $url; // Return as-is, will be filtered out later
            }
        }
        
        // Remove fragment (hash) from URL
        $parsed = parse_url($url);
        
        // If parse_url failed, return original URL
        if ($parsed === false || !isset($parsed['scheme'])) {
            return $url;
        }
        
        if (isset($parsed['fragment'])) {
            unset($parsed['fragment']);
        }
        
        return $this->buildUrl($parsed);
    }

    /**
     * Build URL from parsed components
     */
    public function buildUrl(array $parsed): string
    {
        $url = $parsed['scheme'] . '://';
        if (isset($parsed['user']) && isset($parsed['pass'])) {
            $url .= $parsed['user'] . ':' . $parsed['pass'] . '@';
        }
        $url .= $parsed['host'];
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path']) && $parsed['path'] !== '') {
            $url .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }
        return $url;
    }

    /**
     * Get base URL (scheme + host + port)
     */
    public function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    }

    /**
     * Resolve relative URL to absolute URL
     */
    public function resolveUrl(string $url, string $baseUrl): string
    {
        // Early check for invalid protocols - don't try to resolve them
        $invalidProtocols = ['javascript:', 'tel:', 'mailto:', 'ftp:', 'file:', 'data:', 'blob:', 'about:'];
        foreach ($invalidProtocols as $protocol) {
            if (str_starts_with(strtolower($url), $protocol)) {
                return $url; // Return as-is, will be filtered out later
            }
        }
        
        // Check for fragment-only or invalid patterns
        if (in_array(trim($url), ['#', '', 'void(0)', 'return false']) || str_starts_with($url, '#')) {
            return $url; // Return as-is, will be filtered out later
        }

        // If already absolute, return as-is
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // If starts with //, add protocol
        if (str_starts_with($url, '//')) {
            $protocol = parse_url($baseUrl, PHP_URL_SCHEME);
            return $protocol . ':' . $url;
        }

        // If starts with /, it's absolute path
        if (str_starts_with($url, '/')) {
            $parsed = parse_url($baseUrl);
            if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
                return $url; // Return as-is if can't parse
            }
            return $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $url;
        }

        // Relative URL
        $basePath = dirname(parse_url($baseUrl, PHP_URL_PATH) ?: '/');
        $parsed = parse_url($baseUrl);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return $url; // Return as-is if can't parse
        }
        $base = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $basePath;
        
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Validate if URL is acceptable for crawling (includes visited/queued checks)
     */
    public function isValidCrawlableUrl(string $url, string $baseUrl, bool $followExternalLinks, array $visitedUrls = [], array $queuedUrls = []): bool
    {
        // First do basic URL validation
        if (!$this->isValidUrl($url, $baseUrl, $followExternalLinks)) {
            return false;
        }

        // Check if already visited
        if (in_array($this->normalizeUrl($url), $visitedUrls)) {
            return false;
        }

        // Check if already in queue
        foreach ($queuedUrls as $queuedUrl) {
            if ($this->normalizeUrl($queuedUrl['url']) === $this->normalizeUrl($url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Basic URL validation (without visited/queued checks)
     */
    public function isValidUrl(string $url, string $baseUrl, bool $followExternalLinks): bool
    {
        // Check for invalid protocols and patterns
        $invalidProtocols = ['javascript:', 'tel:', 'mailto:', 'ftp:', 'file:', 'data:', 'blob:', 'about:'];
        foreach ($invalidProtocols as $protocol) {
            if (str_starts_with(strtolower($url), $protocol)) {
                return false;
            }
        }

        // Check for invalid patterns
        $invalidPatterns = [
            '#',           // Fragment-only links
            'void(0)',     // JavaScript void
            'return false' // JavaScript return false
        ];
        foreach ($invalidPatterns as $pattern) {
            if (str_contains(strtolower($url), $pattern)) {
                return false;
            }
        }

        // Check if URL is valid
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Only allow HTTP and HTTPS protocols
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'])) {
            return false;
        }

        // Check if external link
        if (!$followExternalLinks) {
            $urlHost = parse_url($url, PHP_URL_HOST);
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);

            if ($urlHost !== $baseHost) {
                return false;
            }
        }

        return true;
    }
}
