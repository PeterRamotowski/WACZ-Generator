<?php

namespace App\Service\Wacz;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use Psr\Log\LoggerInterface;

class WaczArchiveService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PageContentService $pageContentService,
        private readonly WarcWriterService $warcWriterService,
        private readonly CdxIndexerService $cdxIndexerService,
        private readonly PagesJsonlService $pagesJsonlService,
        private readonly DatapackageService $datapackageService,
        private readonly WaczZipService $waczZipService,
        private readonly string $waczTempDir
    ) {
        if (!is_dir($this->waczTempDir)) {
            mkdir($this->waczTempDir, 0755, true);
        }
    }

    public function createWaczArchive(WaczRequest $waczRequest, array $crawledPages, array $pageContents = []): string
    {
        $requestId = $waczRequest->getId();
        $tempDir = $this->waczTempDir . '/wacz_' . $requestId;

        $this->waczZipService->createWaczDirectoryStructure($tempDir);

        try {
            // Always download/extract content from database since pageContents may be incomplete
            $pageContentData = $this->pageContentService->downloadPageContents($crawledPages);

            // If additional pageContents were provided, merge them in
            if (!empty($pageContents)) {
                foreach ($pageContents as $url => $content) {
                    if (!isset($pageContentData[$url])) {
                        $pageContentData[$url] = is_array($content) ? $content : ['content' => $content, 'headers' => [], 'status_code' => 200];
                    }
                }
            }

            // Create WARC files and get record positions
            $this->warcWriterService->createWarcFiles($crawledPages, $pageContentData, $tempDir);
            $warcRecordPositions = $this->warcWriterService->getWarcRecordPositions();

            // Create CDX index
            $this->cdxIndexerService->createCdxIndex($crawledPages, $pageContentData, $warcRecordPositions, $tempDir);

            // Create pages.jsonl
            $this->pagesJsonlService->createPagesJsonl($crawledPages, $pageContentData, $tempDir);

            // Create datapackage.json and digest
            $this->datapackageService->createDatapackageJson($waczRequest, $crawledPages, $tempDir);
            $this->datapackageService->createDatapackageDigest($tempDir);

            // Create ZIP archive
            $waczFilePath = $this->waczZipService->createZipArchive($waczRequest, $tempDir);

            // Cleanup
            $this->waczZipService->cleanupTempDirectory($tempDir);

            return $waczFilePath;
        } catch (\Exception $e) {
            $this->waczZipService->cleanupTempDirectory($tempDir);
            throw $e;
        }
    }

    public function storePageContent(CrawledPage $crawledPage, string $content): void
    {
        $this->pageContentService->storePageContent($crawledPage, $content);
    }

    public function getStoredPageContent(CrawledPage $crawledPage): ?string
    {
        return $this->pageContentService->getStoredPageContent($crawledPage);
    }
}