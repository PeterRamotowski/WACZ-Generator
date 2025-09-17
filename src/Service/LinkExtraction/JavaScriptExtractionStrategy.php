<?php

namespace App\Service\LinkExtraction;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class JavaScriptExtractionStrategy implements LinkExtractionStrategyInterface
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

            // Extract JavaScript files from script tags
            $crawler->filter('script[src]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks, &$extractedUrls) {
                $src = $node->attr('src');
                $absoluteUrl = $this->urlNormalizer->resolveUrl($src, $crawledPage->getUrl());
                $normalizedUrl = $this->urlNormalizer->normalizeUrl($absoluteUrl);

                if ($this->urlNormalizer->isValidUrl($normalizedUrl, $baseUrl, $followExternalLinks)) {
                    $extractedUrls[] = [
                        'url' => $normalizedUrl,
                        'type' => 'javascript',
                        'depth' => $crawledPage->getDepth()
                    ];
                }
            });

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract JavaScript links', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }

        return $extractedUrls;
    }

    public function getName(): string
    {
        return 'javascript';
    }

    public function supports(?string $contentType): bool
    {
        return $contentType && (
            str_contains($contentType, 'text/html') ||
            str_contains($contentType, 'application/xhtml+xml')
        );
    }
}