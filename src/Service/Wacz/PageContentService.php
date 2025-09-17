<?php

namespace App\Service\Wacz;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use App\Service\ContentTypeService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PageContentService
{
    private array $pageContents = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentType
    ) {
    }

    public function downloadPageContents(array $crawledPages): array
    {
        $this->pageContents = [];

        foreach ($crawledPages as $crawledPage) {
            if (!$crawledPage->isSuccessful()) {
                continue;
            }

            $url = $crawledPage->getUrl();
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);

            if ($crawledPage->getContent()) {
                $this->pageContents[$normalizedUrl] = [
                    'content' => $crawledPage->getContent(),
                    'headers' => $crawledPage->getHeaders() ?? ['content-type' => [$crawledPage->getContentType()]],
                    'status_code' => $crawledPage->getHttpStatusCode()
                ];
                continue;
            }

            if (!isset($this->pageContents[$normalizedUrl])) {
                try {
                    $response = $this->httpClient->request('GET', $url);
                    $content = $response->getContent();

                    $this->pageContents[$normalizedUrl] = [
                        'content' => $content,
                        'headers' => $response->getHeaders(),
                        'status_code' => $response->getStatusCode()
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to download page content for archive', [
                        'url' => $url,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
        }

        return $this->pageContents;
    }

    public function setPageContents(array $pageContents): void
    {
        $this->pageContents = [];
        
        // Convert any string content to the expected array format
        foreach ($pageContents as $url => $content) {
            if (is_string($content)) {
                $this->pageContents[$url] = [
                    'content' => $content,
                    'headers' => ['content-type' => ['text/html']],
                    'status_code' => 200
                ];
            } elseif (is_array($content)) {
                $this->pageContents[$url] = $content;
            }
        }
    }

    public function getPageContents(): array
    {
        return $this->pageContents;
    }

    public function storePageContent(CrawledPage $crawledPage, string $content): void
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $this->pageContents[$normalizedUrl] = [
            'content' => $content,
            'headers' => $crawledPage->getHeaders() ?? ['content-type' => [$crawledPage->getContentType()]],
            'status_code' => $crawledPage->getHttpStatusCode() ?? 200
        ];
    }

    public function getStoredPageContent(CrawledPage $crawledPage): ?string
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $pageData = $this->pageContents[$normalizedUrl] ?? null;
        
        if (is_array($pageData) && isset($pageData['content'])) {
            return $pageData['content'];
        }
        
        return is_string($pageData) ? $pageData : null;
    }

    public function addPageContent(string $normalizedUrl, array $contentData): void
    {
        $this->pageContents[$normalizedUrl] = $contentData;
    }
}