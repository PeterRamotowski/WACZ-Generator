<?php

namespace App\Service;

use App\Entity\WaczRequest;
use App\Service\LinkExtraction\HtmlLinkExtractionStrategy;
use App\Service\LinkExtraction\ImageExtractionStrategy;
use App\Service\LinkExtraction\CssExtractionStrategy;
use App\Service\LinkExtraction\JavaScriptExtractionStrategy;
use Psr\Log\LoggerInterface;

class WebCrawlerService
{
    public function __construct(
        private readonly CrawlOrchestratorService $crawlOrchestrator,
        private readonly LinkExtractorService $linkExtractor,
        private readonly LoggerInterface $logger,
        private readonly HtmlLinkExtractionStrategy $htmlLinkStrategy,
        private readonly ImageExtractionStrategy $imageStrategy,
        private readonly CssExtractionStrategy $cssStrategy,
        private readonly JavaScriptExtractionStrategy $jsStrategy,
    ) {
        $this->initializeLinkExtractor();
    }

    /**
     * Initialize HTTP client with user agent
     */
    public function initHttpClient(string $userAgent): void
    {
        $this->crawlOrchestrator->initHttpClient($userAgent);
    }

    /**
     * Main crawling method - delegates to orchestrator
     */
    public function crawlWebsite(WaczRequest $waczRequest): array
    {
        $this->logger->info('Starting website crawl', [
            'url' => $waczRequest->getUrl(),
            'max_pages' => $waczRequest->getMaxPages(),
            'max_depth' => $waczRequest->getMaxDepth()
        ]);

        $crawledPages = $this->crawlOrchestrator->crawlWebsite($waczRequest);

        $this->logger->info('Website crawl completed', [
            'url' => $waczRequest->getUrl(),
            'pages_crawled' => count($crawledPages)
        ]);

        return $crawledPages;
    }

    /**
     * Get page contents from orchestrator
     */
    public function getPageContents(): array
    {
        return $this->crawlOrchestrator->getPageContents();
    }

    /**
     * Clear page contents from orchestrator
     */
    public function clearPageContents(): void
    {
        $this->crawlOrchestrator->clearPageContents();
    }

    /**
     * Initialize the link extractor with all strategies
     */
    private function initializeLinkExtractor(): void
    {
        $this->linkExtractor->addStrategy($this->htmlLinkStrategy);
        $this->linkExtractor->addStrategy($this->imageStrategy);
        $this->linkExtractor->addStrategy($this->cssStrategy);
        $this->linkExtractor->addStrategy($this->jsStrategy);

        $this->logger->debug('Link extractor initialized with strategies', [
            'strategies' => array_keys($this->linkExtractor->getStrategies())
        ]);
    }
}
