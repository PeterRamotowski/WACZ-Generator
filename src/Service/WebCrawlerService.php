<?php

namespace App\Service;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use App\Repository\CrawledPageRepository;
use App\Service\ContentTypeService;
use App\Service\UrlNormalizerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebCrawlerService
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
        private readonly ContentTypeService $contentType,
    ) {}

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
    }

    public function crawlWebsite(WaczRequest $waczRequest): array
    {
        $this->visitedUrls = [];
        $this->queuedUrls = [];
        $this->pageContents = [];

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

            if (in_array($this->urlNormalizer->normalizeUrl($url), $this->visitedUrls)) {
                continue;
            }

            if ($depth > $waczRequest->getMaxDepth()) {
                continue;
            }

            if ($this->shouldExcludeUrl($url, $options)) {
                continue;
            }

            $this->visitedUrls[] = $this->urlNormalizer->normalizeUrl($url);
            
            try {
                $crawledPage = $this->crawlSinglePage($waczRequest, $url, $depth);
                
                if ($crawledPage) {
                    $crawledPages[] = $crawledPage;
                    $pageCount++;

                    if ($crawledPage->isSuccessful() && $this->contentType->isHtmlContent($crawledPage->getContentType())) {
                        $this->extractLinksFromPage($crawledPage, $waczRequest, $options);
                    }
                }

                if ($waczRequest->getCrawlDelay() > 0) {
                    usleep($waczRequest->getCrawlDelay() * 1000);
                }

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

    private function crawlSinglePage(WaczRequest $waczRequest, string $url, int $depth): ?CrawledPage
    {
        $crawledPage = new CrawledPage();
        $crawledPage->setWaczRequest($waczRequest);
        $crawledPage->setUrl($url);
        $crawledPage->setDepth($depth);
        $defaultTitle = parse_url($url, PHP_URL_HOST) ?? 'Unknown';
        $crawledPage->setTitle($defaultTitle);

        try {
            $startTime = microtime(true);
            $response = $this->httpClient->request('GET', $url);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? null;

            $crawledPage->setHttpStatusCode($statusCode);
            $crawledPage->setContentType($contentType);
            $crawledPage->setHeaders($headers);

            if ($statusCode >= 200 && $statusCode < 300) {
                $content = $response->getContent();

                // Check if content is gzipped and decompress if needed
                $isGzipped = isset($headers['content-encoding']) && in_array('gzip', $headers['content-encoding']);
                if ($isGzipped && $this->isGzipContent($content)) {
                    $decompressedContent = gzinflate(substr($content, 10, -8));
                    if ($decompressedContent !== false) {
                        $content = $decompressedContent;
                    }
                }

                // Get response time after content is received
                $responseTime = (int)($response->getInfo('total_time') * 1000);
                $crawledPage->setResponseTime($responseTime);
                $crawledPage->setContentLength(strlen($content));

                // Extract title for HTML pages
                if ($this->contentType->isTextContent($contentType)) {
                    $title = $this->extractTitleFromHtml($content);
                    // Limit title length to avoid database errors (500 characters max)
                    $safeTitle = $title ?? parse_url($url, PHP_URL_HOST) ?? 'Unknown';
                    if (strlen($safeTitle) > 500) {
                        $safeTitle = substr($safeTitle, 0, 497) . '...';
                    }

                    // Ensure title is properly encoded
                    if (!mb_check_encoding($safeTitle, 'UTF-8')) {
                        $safeTitle = mb_convert_encoding($safeTitle, 'UTF-8', 'auto');
                    }

                    $crawledPage->setTitle($safeTitle);

                    // Ensure content is properly encoded before storage
                    if (!mb_check_encoding($content, 'UTF-8')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'auto');
                    }

                    // Store content for text-based pages
                    $crawledPage->setContent($content);
                } else {
                    // For binary content, use hostname as title
                    $hostname = parse_url($url, PHP_URL_HOST) ?? 'Unknown';
                    $crawledPage->setTitle($hostname);
                    // Don't store binary content
                    $crawledPage->setContent(null);
                }

                $crawledPage->setStatus(CrawledPage::STATUS_SUCCESS);

                // Store content for archive creation
                $this->storePageContent($crawledPage, $content, $waczRequest);

            } else {
                $crawledPage->setStatus(CrawledPage::STATUS_ERROR);
                $crawledPage->setErrorMessage("HTTP {$statusCode}");
                // Set response time even for error responses
                $errorResponseTime = (int)($response->getInfo('total_time') * 1000);
                $crawledPage->setResponseTime($errorResponseTime);
            }

        } catch (\Exception $e) {
            $crawledPage->setStatus(CrawledPage::STATUS_ERROR);
            $crawledPage->setErrorMessage($e->getMessage());

            $this->logger->warning('Failed to crawl page', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }

        try {
            if (!$this->entityManager->isOpen()) {
                return $crawledPage;
            }
            
            $this->crawledPageRepository->save($crawledPage, true);
        } catch (\Exception $e) {
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
            } catch (\Exception $secondaryException) {
                $this->logger->critical('Failed to save crawled page even with fallback', [
                    'url' => $url,
                    'primary_error' => $e->getMessage(),
                    'secondary_error' => $secondaryException->getMessage()
                ]);
                
                // Return a minimal page object to prevent complete failure
                $fallbackPage = new CrawledPage();
                $fallbackPage->setWaczRequest($waczRequest);
                $fallbackPage->setUrl($url);
                $fallbackPage->setDepth($crawledPage->getDepth());
                $fallbackPage->setTitle('DB Error');
                $fallbackPage->setStatus(CrawledPage::STATUS_ERROR);
                $fallbackPage->setErrorMessage('Critical database error');
                return $fallbackPage;
            }
        }

        return $crawledPage;
    }

    private function extractLinksFromPage(CrawledPage $crawledPage, WaczRequest $waczRequest, array $options): void
    {
        try {
            $content = $this->getStoredPageContent($crawledPage, $waczRequest);
            if (!$content) {
                $content = $crawledPage->getContent();
                if (!$content) {
                    return;
                }

                if ($this->isGzipContent($content)) {
                    $decompressedContent = gzinflate(substr($content, 10, -8));
                    if ($decompressedContent !== false) {
                        $content = $decompressedContent;
                    } else {
                        $this->logger->error('Failed to decompress gzipped content from database', [
                            'url' => $crawledPage->getUrl()
                        ]);
                        return;
                    }
                }
            }

            $crawler = new Crawler($content, $crawledPage->getUrl());
            $baseUrl = $this->urlNormalizer->getBaseUrl($crawledPage->getUrl());
            $followExternalLinks = $options['followExternalLinks'] ?? false;

            $linksFound = 0;
            $crawler->filter('a[href]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks, &$linksFound) {
                $href = $node->attr('href');
                $absoluteUrl = $this->urlNormalizer->resolveUrl($href, $crawledPage->getUrl());
                $normalizedUrl = $this->urlNormalizer->normalizeUrl($absoluteUrl);

                if ($this->urlNormalizer->isValidUrl($normalizedUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
                    $this->addUrlToQueue($normalizedUrl, $crawledPage->getDepth() + 1);
                    $linksFound++;
                } else {
                    $this->logger->debug('URL rejected', [
                        'url' => $normalizedUrl,
                        'reason' => 'validation failed'
                    ]);
                }
            });

            if ($options['includeImages'] ?? true) {
                $crawler->filter('img[src]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks) {
                    $src = $node->attr('src');
                    $absoluteUrl = $this->urlNormalizer->resolveUrl($src, $crawledPage->getUrl());
                    
                    if ($this->urlNormalizer->isValidUrl($absoluteUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
                        $this->addUrlToQueue($absoluteUrl, $crawledPage->getDepth());
                    }
                });

                $this->extractBackgroundImages($content, $crawledPage, $baseUrl, $followExternalLinks);
            }

            if ($options['includeCSS'] ?? true) {
                $crawler->filter('link[rel="stylesheet"][href]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks) {
                    $href = $node->attr('href');
                    $absoluteUrl = $this->urlNormalizer->resolveUrl($href, $crawledPage->getUrl());
                    
                    if ($this->urlNormalizer->isValidUrl($absoluteUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
                        $this->addUrlToQueue($absoluteUrl, $crawledPage->getDepth());
                    }
                });
            }

            if ($options['includeJS'] ?? true) {
                $crawler->filter('script[src]')->each(function (Crawler $node) use ($baseUrl, $crawledPage, $followExternalLinks) {
                    $src = $node->attr('src');
                    $absoluteUrl = $this->urlNormalizer->resolveUrl($src, $crawledPage->getUrl());
                    
                    if ($this->urlNormalizer->isValidUrl($absoluteUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
                        $this->addUrlToQueue($absoluteUrl, $crawledPage->getDepth());
                    }
                });
            }

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract links from page', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }
    }

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

    private function extractTitleFromHtml(string $html): ?string
    {
        try {
            $crawler = new Crawler($html);
            $titleNode = $crawler->filter('title')->first();
            
            if ($titleNode->count() > 0) {
                $title = trim($titleNode->text());
                return $title !== '' ? $title : null;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract title from HTML', [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    private function storePageContent(CrawledPage $crawledPage, string $content, WaczRequest $waczRequest): void
    {
        if ($this->contentType->isTextContent($crawledPage->getContentType())) {
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
            $this->pageContents[$normalizedUrl] = $content;
        }
    }

    private function getStoredPageContent(CrawledPage $crawledPage, WaczRequest $waczRequest): ?string
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $content = $this->pageContents[$normalizedUrl] ?? null;
        return $content;
    }

    private function isGzipContent(string $content): bool
    {
        return strlen($content) >= 2 && substr($content, 0, 2) === "\x1f\x8b";
    }

    public function getPageContents(): array
    {
        return $this->pageContents;
    }

    public function clearPageContents(): void
    {
        $this->pageContents = [];
    }

    private function extractBackgroundImages(string $content, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): void
    {
        try {
            // Extract inline styles with background-image
            $inlineStylePattern = '/style\s*=\s*["\'][^"\']*background-image\s*:\s*url\(["\']?([^)"\'\s]+)["\']?\)[^"\']*["\']/i';
            if (preg_match_all($inlineStylePattern, $content, $matches)) {
                foreach ($matches[1] as $imageUrl) {
                    $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
                }
            }

            // Extract from <style> tags
            $styleTagPattern = '/<style[^>]*>(.*?)<\/style>/is';
            if (preg_match_all($styleTagPattern, $content, $styleMatches)) {
                foreach ($styleMatches[1] as $cssContent) {
                    $this->extractBackgroundImagesFromCSS($cssContent, $crawledPage, $baseUrl, $followExternalLinks);
                }
            }

            // Extract from CSS files referenced in the page
            $crawler = new Crawler($content, $crawledPage->getUrl());
            $crawler->filter('link[rel="stylesheet"][href]')->each(function (Crawler $node) use ($crawledPage, $baseUrl, $followExternalLinks) {
                $href = $node->attr('href');
                $absoluteUrl = $this->urlNormalizer->resolveUrl($href, $crawledPage->getUrl());
                
                // If CSS file is from the same domain, try to fetch and parse it for background images
                if ($this->urlNormalizer->isValidUrl($absoluteUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
                    $this->processCSSFile($absoluteUrl, $crawledPage, $baseUrl, $followExternalLinks);
                }
            });

        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract background images', [
                'url' => $crawledPage->getUrl(),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function extractBackgroundImagesFromCSS(string $cssContent, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): void
    {
        // Pattern to match background-image: url(...) declarations
        $backgroundImagePattern = '/background-image\s*:\s*url\(["\']?([^)"\'\s]+)["\']?\)/i';
        if (preg_match_all($backgroundImagePattern, $cssContent, $matches)) {
            foreach ($matches[1] as $imageUrl) {
                $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
            }
        }

        // Also check for shorthand background property
        $backgroundPattern = '/background\s*:\s*[^;]*url\(["\']?([^)"\'\s]+)["\']?\)[^;]*/i';
        if (preg_match_all($backgroundPattern, $cssContent, $matches)) {
            foreach ($matches[1] as $imageUrl) {
                $this->processBackgroundImageUrl($imageUrl, $crawledPage, $baseUrl, $followExternalLinks);
            }
        }
    }

    private function processBackgroundImageUrl(string $imageUrl, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): void
    {
        // Skip data URLs and invalid URLs
        if (strpos($imageUrl, 'data:') === 0 || empty(trim($imageUrl))) {
            return;
        }

        $absoluteUrl = $this->urlNormalizer->resolveUrl($imageUrl, $crawledPage->getUrl());

        if ($this->urlNormalizer->isValidUrl($absoluteUrl, $baseUrl, $followExternalLinks, $this->visitedUrls, $this->queuedUrls)) {
            $this->addUrlToQueue($absoluteUrl, $crawledPage->getDepth());
        }
    }

    private function processCSSFile(string $cssUrl, CrawledPage $crawledPage, string $baseUrl, bool $followExternalLinks): void
    {
        try {
            $normalizedCssUrl = $this->urlNormalizer->normalizeUrl($cssUrl);
            if (in_array($normalizedCssUrl, $this->visitedUrls)) {
                return;
            }

            $response = $this->httpClient->request('GET', $cssUrl, [
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $cssContent = $response->getContent();
                $this->extractBackgroundImagesFromCSS($cssContent, $crawledPage, $baseUrl, $followExternalLinks);
            }

        } catch (\Exception $e) {
            $this->logger->debug('Failed to fetch CSS file for background image extraction', [
                'css_url' => $cssUrl,
                'error' => $e->getMessage()
            ]);
        }
    }
}
