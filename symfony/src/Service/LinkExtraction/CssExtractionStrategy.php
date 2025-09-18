<?php

namespace App\Service\LinkExtraction;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class CssExtractionStrategy implements LinkExtractionStrategyInterface
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

            // Extract CSS files from link tags
            $crawler->filter('link[rel="stylesheet"][href]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks, &$extractedUrls) {
                $href = $node->attr('href');
                $absoluteUrl = $this->urlNormalizer->resolveUrl($href, $crawledPage->getUrl());
                $normalizedUrl = $this->urlNormalizer->normalizeUrl($absoluteUrl);

                if ($this->urlNormalizer->isValidUrl($normalizedUrl, $baseUrl, $followExternalLinks)) {
                    $extractedUrls[] = [
                        'url' => $normalizedUrl,
                        'type' => 'css',
                        'depth' => $crawledPage->getDepth()
                    ];
                }
            });

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract CSS links', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }

        return $extractedUrls;
    }

    public function getName(): string
    {
        return 'css';
    }

    public function supports(?string $contentType): bool
    {
        return $contentType && (
            str_contains($contentType, 'text/html') ||
            str_contains($contentType, 'application/xhtml+xml')
        );
    }
}