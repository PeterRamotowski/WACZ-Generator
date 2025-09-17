<?php

namespace App\Service;

use App\Entity\CrawledPage;
use App\Service\LinkExtraction\LinkExtractionStrategyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinkExtractorService
{
    /** @var LinkExtractionStrategyInterface[] */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentTypeService,
        private ?HttpClientInterface $httpClient = null
    ) {}

    /**
     * Register a link extraction strategy
     */
    public function addStrategy(LinkExtractionStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    /**
     * Extract links from a page using appropriate strategies based on options
     */
    public function extractLinksFromPage(CrawledPage $crawledPage, string $content, array $options): array
    {
        $allExtractedUrls = [];
        $baseUrl = $this->urlNormalizer->getBaseUrl($crawledPage->getUrl());
        $followExternalLinks = $options['followExternalLinks'] ?? false;

        try {
            // Decompress content if needed
            $processedContent = $this->processContent($content, $crawledPage);
            
            if (!$processedContent) {
                return [];
            }

            // Apply strategies based on options
            if ($options['includeImages'] ?? true) {
                $imageUrls = $this->extractByStrategy('images', $processedContent, $crawledPage, $baseUrl, $followExternalLinks);
                $allExtractedUrls = array_merge($allExtractedUrls, $imageUrls);
            }

            if ($options['includeCSS'] ?? true) {
                $cssUrls = $this->extractByStrategy('css', $processedContent, $crawledPage, $baseUrl, $followExternalLinks);
                $allExtractedUrls = array_merge($allExtractedUrls, $cssUrls);
            }

            if ($options['includeJS'] ?? true) {
                $jsUrls = $this->extractByStrategy('javascript', $processedContent, $crawledPage, $baseUrl, $followExternalLinks);
                $allExtractedUrls = array_merge($allExtractedUrls, $jsUrls);
            }

            // Always extract HTML links for navigation
            $htmlUrls = $this->extractByStrategy('html_links', $processedContent, $crawledPage, $baseUrl, $followExternalLinks);
            $allExtractedUrls = array_merge($allExtractedUrls, $htmlUrls);

            // Additional processing for CSS files if they contain background images
            if (($options['includeImages'] ?? true) && ($options['includeCSS'] ?? true)) {
                $cssBackgroundUrls = $this->extractBackgroundImagesFromCSSFiles($allExtractedUrls, $crawledPage, $baseUrl, $followExternalLinks);
                $allExtractedUrls = array_merge($allExtractedUrls, $cssBackgroundUrls);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to extract links from page', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }

        return $this->deduplicateUrls($allExtractedUrls);
    }

    /**
     * Extract links using a specific strategy
     */
    private function extractByStrategy(
        string $strategyName, 
        string $content, 
        CrawledPage $crawledPage, 
        string $baseUrl, 
        bool $followExternalLinks
    ): array {
        if (!isset($this->strategies[$strategyName])) {
            $this->logger->warning('Strategy not found', ['strategy' => $strategyName]);
            return [];
        }

        $strategy = $this->strategies[$strategyName];

        if (!$strategy->supports($crawledPage->getContentType())) {
            return [];
        }

        return $strategy->extractLinks($content, $crawledPage, $baseUrl, $followExternalLinks);
    }

    /**
     * Process content (handle compression, encoding, etc.)
     */
    private function processContent(string $content, CrawledPage $crawledPage): ?string
    {
        try {
            // Check if content is gzipped and decompress if needed
            if ($this->isGzipContent($content)) {
                $decompressedContent = gzinflate(substr($content, 10, -8));
                if ($decompressedContent !== false) {
                    $content = $decompressedContent;
                } else {
                    $this->logger->error('Failed to decompress gzipped content', [
                        'url' => $crawledPage->getUrl()
                    ]);
                    return null;
                }
            }

            return $content;

        } catch (\Exception $e) {
            $this->logger->error('Failed to process content', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract background images from CSS files
     */
    private function extractBackgroundImagesFromCSSFiles(
        array $extractedUrls, 
        CrawledPage $crawledPage, 
        string $baseUrl, 
        bool $followExternalLinks
    ): array {
        $backgroundImageUrls = [];

        if (!$this->httpClient) {
            return $backgroundImageUrls;
        }

        foreach ($extractedUrls as $urlData) {
            if ($urlData['type'] === 'css') {
                try {
                    $cssContent = $this->fetchCSSContent($urlData['url']);
                    if ($cssContent) {
                        $imageStrategy = $this->strategies['images'] ?? null;
                        if ($imageStrategy) {
                            // Create a temporary crawled page for CSS processing
                            $tempCrawledPage = clone $crawledPage;
                            $tempCrawledPage->setUrl($urlData['url']);

                            $cssImages = $imageStrategy->extractLinks($cssContent, $tempCrawledPage, $baseUrl, $followExternalLinks);
                            $backgroundImageUrls = array_merge($backgroundImageUrls, $cssImages);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->debug('Failed to process CSS file for background images', [
                        'css_url' => $urlData['url'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $backgroundImageUrls;
    }

    /**
     * Fetch CSS content from URL
     */
    private function fetchCSSContent(string $cssUrl): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $cssUrl, [
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->getContent();
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to fetch CSS content', [
                'url' => $cssUrl,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Remove duplicate URLs from the extracted list
     */
    private function deduplicateUrls(array $urls): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($urls as $urlData) {
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($urlData['url']);
            
            if (!in_array($normalizedUrl, $seen)) {
                $seen[] = $normalizedUrl;
                $urlData['url'] = $normalizedUrl;
                $deduplicated[] = $urlData;
            }
        }

        return $deduplicated;
    }

    /**
     * Check if content is gzipped
     */
    private function isGzipContent(string $content): bool
    {
        return strlen($content) >= 2 && substr($content, 0, 2) === "\x1f\x8b";
    }

    /**
     * Get all registered strategies
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Set HTTP client for CSS processing
     */
    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }
}