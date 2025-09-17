<?php

namespace App\Service\Wacz;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use App\Service\ContentTypeService;
use Psr\Log\LoggerInterface;

class PagesJsonlService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentType
    ) {
    }

    public function createPagesJsonl(array $crawledPages, array $pageContents, string $tempDir): void
    {
        $pagesFilePath = $tempDir . '/pages/pages.jsonl';
        $pagesFile = fopen($pagesFilePath, 'w');
        
        if (!$pagesFile) {
            throw new \Exception('Could not create pages.jsonl file');
        }

        // Write header record with format metadata
        $headerRecord = [
            'format' => 'json-pages-1.0',
            'id' => 'pages',
            'title' => 'All Pages',
            'hasText' => true
        ];
        fwrite($pagesFile, json_encode($headerRecord) . "\n");

        foreach ($crawledPages as $crawledPage) {
            if (!$crawledPage->isSuccessful()) {
                continue;
            }

            $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
            $pageContent = $pageContents[$normalizedUrl] ?? null;
            $textContent = '';
            
            if ($pageContent && isset($pageContent['content'])) {
                $textContent = $this->contentType->extractTextFromHtml($pageContent['content']);
            }

            $pageData = [
                'id' => $this->generateShortId(),
                'url' => $crawledPage->getUrl(),
                'title' => $crawledPage->getTitle() ?? '',
                'ts' => $crawledPage->getCrawledAt()->format('Y-m-d\TH:i:s.v\Z'),
                'load_state' => 1,
                'size' => $crawledPage->getContentLength() ?? 0,
                'seed_id' => 0,
                'text' => $textContent
            ];

            $jsonLine = json_encode($pageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonLine === false) {
                $this->logger->warning('Failed to encode page data to JSON', [
                    'url' => $crawledPage->getUrl(),
                    'json_error' => json_last_error_msg()
                ]);

                // Create minimal record without problematic text content
                $safePageData = [
                    'id' => $pageData['id'],
                    'url' => $pageData['url'],
                    'title' => $this->contentType->sanitizeForJson($pageData['title'], 500),
                    'ts' => $pageData['ts'],
                    'load_state' => $pageData['load_state'],
                    'size' => $pageData['size'],
                    'seed_id' => $pageData['seed_id'],
                    'text' => ''
                ];
                $jsonLine = json_encode($safePageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            
            fwrite($pagesFile, $jsonLine . "\n");
        }

        fclose($pagesFile);
    }

    private function generateShortId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $length = 22;
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }
}