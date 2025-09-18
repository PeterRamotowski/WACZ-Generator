<?php

namespace App\Service\LinkExtraction;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class ImageExtractionStrategy implements LinkExtractionStrategyInterface
{
    public function __construct(
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly LoggerInterface $logger
    ) {}

    public function extractLinks(string $content, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): array
    {
        $extractedUrls = [];

        try {
            $crawler = new Crawler($content, $crawledPage->getUrl());

            // Extract images from img tags
            $crawler->filter('img[src]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks, &$extractedUrls) {
                $src = $node->attr('src');
                $absoluteUrl = $this->urlNormalizer->resolveUrl($src, $crawledPage->getUrl());
                $normalizedUrl = $this->urlNormalizer->normalizeUrl($absoluteUrl);

                if ($this->urlNormalizer->isValidUrl($normalizedUrl, $baseUrl, $followExternalLinks)) {
                    $extractedUrls[] = [
                        'url' => $normalizedUrl,
                        'type' => 'image',
                        'depth' => $crawledPage->getDepth()
                    ];
                }
            });

            // Extract background images
            $backgroundImages = $this->extractBackgroundImages($content, $crawledPage, $baseUrl, $followExternalLinks);
            $extractedUrls = array_merge($extractedUrls, $backgroundImages);

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract images', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }

        return $extractedUrls;
    }

    public function getName(): string
    {
        return 'images';
    }

    public function supports(?string $contentType): bool
    {
        return $contentType && (
            str_contains($contentType, 'text/html') ||
            str_contains($contentType, 'application/xhtml+xml')
        );
    }

    private function extractBackgroundImages(string $content, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): array
    {
        $extractedUrls = [];

        try {
            // Extract inline styles with background-image
            $inlineStylePattern = '/style\s*=\s*["\'][^"\']*background-image\s*:\s*url\(["\']?([^)"\'\s]+)["\']?\)[^"\']*["\']/i';
            if (preg_match_all($inlineStylePattern, $content, $matches)) {
                foreach ($matches[1] as $imageUrl) {
                    $processedUrl = $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
                    if ($processedUrl) {
                        $extractedUrls[] = $processedUrl;
                    }
                }
            }

            // Extract from <style> tags
            $styleTagPattern = '/<style[^>]*>(.*?)<\/style>/is';
            if (preg_match_all($styleTagPattern, $content, $styleMatches)) {
                foreach ($styleMatches[1] as $cssContent) {
                    $cssImages = $this->extractBackgroundImagesFromCSS($cssContent, $crawledPage, $baseUrl, $followExternalLinks);
                    $extractedUrls = array_merge($extractedUrls, $cssImages);
                }
            }

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract background images', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }

        return $extractedUrls;
    }

    private function extractBackgroundImagesFromCSS(string $cssContent, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): array
    {
        $extractedUrls = [];

        // Pattern to match background-image: url(...) declarations
        $backgroundImagePattern = '/background-image\s*:\s*url\(["\']?([^)"\'\s]+)["\']?\)/i';
        if (preg_match_all($backgroundImagePattern, $cssContent, $matches)) {
            foreach ($matches[1] as $imageUrl) {
                $processedUrl = $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
                if ($processedUrl) {
                    $extractedUrls[] = $processedUrl;
                }
            }
        }

        // Also check for shorthand background property
        $backgroundPattern = '/background\s*:\s*[^;]*url\(["\']?([^)"\'\s]+)["\']?\)[^;]*/i';
        if (preg_match_all($backgroundPattern, $cssContent, $matches)) {
            foreach ($matches[1] as $imageUrl) {
                $processedUrl = $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
                if ($processedUrl) {
                    $extractedUrls[] = $processedUrl;
                }
            }
        }

        return $extractedUrls;
    }

    private function processBackgroundImageUrl(string $imageUrl, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): ?array
    {
        // Skip data URLs and invalid URLs
        if (strpos($imageUrl, 'data:') === 0 || empty(trim($imageUrl))) {
            return null;
        }

        $absoluteUrl = $this->urlNormalizer->resolveUrl($imageUrl, $crawledPage->getUrl());
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($absoluteUrl);

        if ($this->urlNormalizer->isValidUrl($normalizedUrl, $baseUrl, $followExternalLinks)) {
            return [
                'url' => $normalizedUrl,
                'type' => 'background_image',
                'depth' => $crawledPage->getDepth()
            ];
        }

        return null;
    }
}