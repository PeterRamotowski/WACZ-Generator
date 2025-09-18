<?php

namespace App\Service;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use App\Repository\CrawledPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CrawlOrchestratorService
{
    private ?HttpClientInterface $httpClient = null;
    private array $visitedUrls = [];
    private array $queuedUrls = [];
    private array $pageContents = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CrawledPageRepository $crawledPageRepository,
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentTypeService,
        private readonly LinkExtractorService $linkExtractor
    ) {}

    /**
     * Initialize HTTP client with user agent
     */
    public function initHttpClient(string $userAgent): void
    {
        $this->httpClient = HttpClient::create([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'pl,en-US;q=0.7,en;q=0.3',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ]
        ]);

        // Set HTTP client for link extractor
        $this->linkExtractor->setHttpClient($this->httpClient);
    }

    /**
     * Orchestrate the entire crawling process
     */
    public function crawlWebsite(WaczRequest $waczRequest): array
    {
        $this->resetCrawlState();

        // Initialize with starting URL
        $this->queuedUrls[] = [
            'url' => $this->urlNormalizer->normalizeUrl($waczRequest->getUrl()),
            'depth' => 0,
            'parent_url' => null
        ];

        $crawledPages = [];
        $pageCount = 0;
        $options = $waczRequest->getMetadata()['options'] ?? [];
        
        while (!empty($this->queuedUrls) && $pageCount < $waczRequest->getMaxPages()) {
            $urlData = array_shift($this->queuedUrls);
            $url = $urlData['url'];
            $depth = $urlData['depth'];

            if ($this->shouldSkipUrl($url, $depth, $waczRequest, $options)) {
                continue;
            }

            $this->markUrlAsVisited($url);
            
            try {
                $crawledPage = $this->crawlSinglePage($waczRequest, $url, $depth);
                
                if ($crawledPage) {
                    $crawledPages[] = $crawledPage;
                    $pageCount++;

                    if ($this->shouldExtractLinks($crawledPage)) {
                        $this->processLinksFromPage($crawledPage, $waczRequest, $options);
                    }
                }

                $this->applyCrawlDelay($waczRequest);

            } catch (\Exception $e) {
                $this->logger->error('Error crawling page', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return $crawledPages;
    }

    /**
     * Crawl a single page
     */
    private function crawlSinglePage(WaczRequest $waczRequest, string $url, int $depth): ?CrawledPage
    {
        $crawledPage = $this->createCrawledPage($waczRequest, $url, $depth);

        try {
            $response = $this->httpClient->request('GET', $url);
            $this->processHttpResponse($response, $crawledPage, $waczRequest);

        } catch (\Exception $e) {
            $this->handleCrawlError($crawledPage, $e);
        }

        return $this->saveCrawledPage($crawledPage, $url);
    }

    /**
     * Process HTTP response and populate crawled page
     */
    private function processHttpResponse($response, CrawledPage $crawledPage, WaczRequest $waczRequest): void
    {
        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders();
        $contentType = $headers['content-type'][0] ?? null;

        $crawledPage->setHttpStatusCode($statusCode);
        $crawledPage->setContentType($contentType);
        $crawledPage->setHeaders($headers);

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->processSuccessfulResponse($response, $crawledPage, $waczRequest);
        } else {
            $this->processErrorResponse($response, $crawledPage);
        }
    }

    /**
     * Process successful HTTP response
     */
    private function processSuccessfulResponse($response, CrawledPage $crawledPage, WaczRequest $waczRequest): void
    {
        $content = $response->getContent();

        // Handle compressed content
        $content = $this->handleCompression($content, $crawledPage);

        // Set response metrics
        $responseTime = (int)($response->getInfo('total_time') * 1000);
        $crawledPage->setResponseTime($responseTime);
        $crawledPage->setContentLength(strlen($content));

        // Process content based on type
        if ($this->contentTypeService->isTextContent($crawledPage->getContentType())) {
            $this->processTextContent($content, $crawledPage);
        } else {
            $this->processBinaryContent($crawledPage);
        }

        $crawledPage->setStatus(CrawledPage::STATUS_SUCCESS);

        // Store content for archive creation
        $this->storePageContent($crawledPage, $content, $waczRequest);
    }

    /**
     * Process text content (HTML, CSS, JS, etc.)
     */
    private function processTextContent(string $content, CrawledPage $crawledPage): void
    {
        // Extract and set title
        $title = $this->extractTitleFromContent($content, $crawledPage->getUrl());
        $safeTitle = $this->sanitizeTitle($title, $crawledPage->getUrl());
        $crawledPage->setTitle($safeTitle);

        // Ensure proper encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }

        $crawledPage->setContent($content);
    }

    /**
     * Process binary content
     */
    private function processBinaryContent(CrawledPage $crawledPage): void
    {
        $hostname = parse_url($crawledPage->getUrl(), PHP_URL_HOST) ?? 'Unknown';
        $crawledPage->setTitle($hostname);
        $crawledPage->setContent(null);
    }

    /**
     * Process error HTTP response
     */
    private function processErrorResponse($response, CrawledPage $crawledPage): void
    {
        $statusCode = $response->getStatusCode();
        $crawledPage->setStatus(CrawledPage::STATUS_ERROR);
        $crawledPage->setErrorMessage("HTTP {$statusCode}");
        
        $errorResponseTime = (int)($response->getInfo('total_time') * 1000);
        $crawledPage->setResponseTime($errorResponseTime);
    }

    /**
     * Process links from a crawled page
     */
    private function processLinksFromPage(CrawledPage $crawledPage, WaczRequest $waczRequest, array $options): void
    {
        try {
            $content = $this->getPageContentForLinkExtraction($crawledPage, $waczRequest);
            if (!$content) {
                return;
            }

            $extractedUrls = $this->linkExtractor->extractLinksFromPage($crawledPage, $content, $options);
            
            foreach ($extractedUrls as $urlData) {
                $this->addUrlToQueue($urlData['url'], $urlData['depth']);
            }

            $this->logger->debug('Links extracted from page', [
                'url' => $crawledPage->getUrl(),
                'links_found' => count($extractedUrls)
            ]);

        } catch (\Exception $e) {
            $this->logger->warning('Failed to process links from page', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get page content for link extraction
     */
    private function getPageContentForLinkExtraction(CrawledPage $crawledPage, WaczRequest $waczRequest): ?string
    {
        // Try to get from stored content first
        $content = $this->getStoredPageContent($crawledPage, $waczRequest);
        
        if (!$content) {
            $content = $crawledPage->getContent();
        }

        return $content;
    }

    /**
     * Create a new CrawledPage entity
     */
    private function createCrawledPage(WaczRequest $waczRequest, string $url, int $depth): CrawledPage
    {
        $crawledPage = new CrawledPage();
        $crawledPage->setWaczRequest($waczRequest);
        $crawledPage->setUrl($url);
        $crawledPage->setDepth($depth);
        
        $defaultTitle = parse_url($url, PHP_URL_HOST) ?? 'Unknown';
        $crawledPage->setTitle($defaultTitle);

        return $crawledPage;
    }

    /**
     * Handle crawl errors
     */
    private function handleCrawlError(CrawledPage $crawledPage, \Exception $e): void
    {
        $crawledPage->setStatus(CrawledPage::STATUS_ERROR);
        $crawledPage->setErrorMessage($e->getMessage());

        $this->logger->warning('Failed to crawl page', [
            'url' => $crawledPage->getUrl(),
            'error' => $e->getMessage()
        ]);
    }

    /**
     * Save crawled page to database
     */
    private function saveCrawledPage(CrawledPage $crawledPage, string $url): ?CrawledPage
    {
        try {
            if (!$this->entityManager->isOpen()) {
                return $crawledPage;
            }
            
            $this->crawledPageRepository->save($crawledPage, true);
            return $crawledPage;

        } catch (\Exception $e) {
            return $this->handleSaveError($crawledPage, $e, $url);
        }
    }

    /**
     * Handle database save errors
     */
    private function handleSaveError(CrawledPage $crawledPage, \Exception $e, string $url): CrawledPage
    {
        $this->logger->error('Failed to save crawled page to database', [
            'url' => $url,
            'error' => $e->getMessage(),
            'title_length' => strlen($crawledPage->getTitle() ?? ''),
            'title' => substr($crawledPage->getTitle() ?? '', 0, 100) . '...'
        ]);

        $crawledPage->setTitle('Error');
        $crawledPage->setStatus(CrawledPage::STATUS_ERROR);
        $crawledPage->setErrorMessage('Database save error: ' . $e->getMessage());
        
        try {
            if ($this->entityManager->isOpen()) {
                $this->crawledPageRepository->save($crawledPage, true);
            }
            return $crawledPage;
        } catch (\Exception $secondaryException) {
            return $this->createFallbackPage($crawledPage, $e, $secondaryException, $url);
        }
    }

    /**
     * Create a fallback page when all save attempts fail
     */
    private function createFallbackPage(CrawledPage $originalPage, \Exception $primaryError, \Exception $secondaryError, string $url): CrawledPage
    {
        $this->logger->critical('Failed to save crawled page even with fallback', [
            'url' => $url,
            'primary_error' => $primaryError->getMessage(),
            'secondary_error' => $secondaryError->getMessage()
        ]);
        
        $fallbackPage = new CrawledPage();
        $fallbackPage->setWaczRequest($originalPage->getWaczRequest());
        $fallbackPage->setUrl($url);
        $fallbackPage->setDepth($originalPage->getDepth());
        $fallbackPage->setTitle('DB Error');
        $fallbackPage->setStatus(CrawledPage::STATUS_ERROR);
        $fallbackPage->setErrorMessage('Critical database error');
        
        return $fallbackPage;
    }

    /**
     * Reset crawl state for new crawl
     */
    private function resetCrawlState(): void
    {
        $this->visitedUrls = [];
        $this->queuedUrls = [];
        $this->pageContents = [];
    }

    /**
     * Check if URL should be skipped
     */
    private function shouldSkipUrl(string $url, int $depth, WaczRequest $waczRequest, array $options): bool
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);

        // Check if already visited
        if (in_array($normalizedUrl, $this->visitedUrls)) {
            return true;
        }

        // Check depth limit
        if ($depth > $waczRequest->getMaxDepth()) {
            return true;
        }

        // Check exclusion rules
        if ($this->shouldExcludeUrl($url, $options)) {
            return true;
        }

        return false;
    }

    /**
     * Check if URL should be excluded based on options
     */
    private function shouldExcludeUrl(string $url, array $options): bool
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);

        $excludeUrls = $options['excludeUrls'] ?? [];
        if (in_array($normalizedUrl, $excludeUrls)) {
            return true;
        }

        $excludePatterns = $options['excludePatterns'] ?? [];
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $normalizedUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark URL as visited
     */
    private function markUrlAsVisited(string $url): void
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);
        $this->visitedUrls[] = $normalizedUrl;
    }

    /**
     * Check if links should be extracted from page
     */
    private function shouldExtractLinks(CrawledPage $crawledPage): bool
    {
        return $crawledPage->isSuccessful() && 
               $this->contentTypeService->isHtmlContent($crawledPage->getContentType());
    }

    /**
     * Apply crawl delay
     */
    private function applyCrawlDelay(WaczRequest $waczRequest): void
    {
        if ($waczRequest->getCrawlDelay() > 0) {
            usleep($waczRequest->getCrawlDelay() * 1000);
        }
    }

    /**
     * Add URL to crawl queue
     */
    private function addUrlToQueue(string $url, int $depth): void
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);

        foreach ($this->queuedUrls as $queuedUrl) {
            if ($this->urlNormalizer->normalizeUrl($queuedUrl['url']) === $normalizedUrl) {
                return;
            }
        }

        $this->queuedUrls[] = [
            'url' => $normalizedUrl,
            'depth' => $depth,
        ];
    }

    /**
     * Extract title from content
     */
    private function extractTitleFromContent(string $content, string $url): ?string
    {
        try {
            // Only try to extract title from HTML content
            if ($this->contentTypeService->isHtmlContent('text/html')) {
                $crawler = new \Symfony\Component\DomCrawler\Crawler($content);
                $titleNode = $crawler->filter('title')->first();
                
                if ($titleNode->count() > 0) {
                    $title = trim($titleNode->text());
                    return $title !== '' ? $title : null;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract title from content', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Sanitize title for database storage
     */
    private function sanitizeTitle(?string $title, string $url): string
    {
        $safeTitle = $title ?? parse_url($url, PHP_URL_HOST) ?? 'Unknown';
        
        // Limit title length
        if (strlen($safeTitle) > 500) {
            $safeTitle = substr($safeTitle, 0, 497) . '...';
        }

        // Ensure proper encoding
        if (!mb_check_encoding($safeTitle, 'UTF-8')) {
            $safeTitle = mb_convert_encoding($safeTitle, 'UTF-8', 'auto');
        }

        return $safeTitle;
    }

    /**
     * Handle content compression
     */
    private function handleCompression(string $content, CrawledPage $crawledPage): string
    {
        $headers = $crawledPage->getHeaders();
        $isGzipped = isset($headers['content-encoding']) && in_array('gzip', $headers['content-encoding']);
        
        if ($isGzipped && $this->isGzipContent($content)) {
            $decompressedContent = gzinflate(substr($content, 10, -8));
            if ($decompressedContent !== false) {
                return $decompressedContent;
            }
        }

        return $content;
    }

    /**
     * Check if content is gzipped
     */
    private function isGzipContent(string $content): bool
    {
        return strlen($content) >= 2 && substr($content, 0, 2) === "\x1f\x8b";
    }

    /**
     * Store page content for archive creation
     */
    private function storePageContent(CrawledPage $crawledPage, string $content, WaczRequest $waczRequest): void
    {
        if ($this->contentTypeService->isTextContent($crawledPage->getContentType())) {
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
            $this->pageContents[$normalizedUrl] = $content;
        }
    }

    /**
     * Get stored page content
     */
    private function getStoredPageContent(CrawledPage $crawledPage, WaczRequest $waczRequest): ?string
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        return $this->pageContents[$normalizedUrl] ?? null;
    }

    /**
     * Get all page contents
     */
    public function getPageContents(): array
    {
        return $this->pageContents;
    }

    /**
     * Clear stored page contents
     */
    public function clearPageContents(): void
    {
        $this->pageContents = [];
    }
}