<?php

namespace App\Service\Wacz;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;

class CdxIndexerService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer
    ) {
    }

    public function createCdxIndex(array $crawledPages, array $pageContents, array $warcRecordPositions, string $tempDir): void
    {
        $cdxFilePath = $tempDir . '/indexes/index.cdx';
        $cdxFile = fopen($cdxFilePath, 'w');
        
        if (!$cdxFile) {
            throw new \Exception('Could not create CDXJ file');
        }

        foreach ($crawledPages as $crawledPage) {
            if (!$crawledPage->isSuccessful()) {
                continue;
            }

            $url = $crawledPage->getUrl();
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);
            $content = $pageContents[$normalizedUrl]['content'] ?? '';
            
            // Get real WARC record position
            $recordPosition = $warcRecordPositions[$normalizedUrl] ?? null;
            if (!$recordPosition) {
                continue;
            }
            
            // Create SURT (Sort-friendly URI Reordering Transform)
            $surt = $this->createSurt($url);
            
            // Create timestamp in CDXJ format (17 digits with milliseconds)
            $timestamp = $crawledPage->getCrawledAt()->format('YmdHis') . '000'; // Add milliseconds
            
            // Create JSON block with required fields
            $contentDigest = hash('sha256', $content);
            $jsonBlock = [
                'url' => $url,
                'digest' => 'sha-256:' . $contentDigest, // WACZ format uses sha-256 prefix
                'mime' => $crawledPage->getContentType() ?? 'text/html',
                'offset' => $recordPosition['offset'],
                'length' => $recordPosition['length'],
                'recordDigest' => $recordPosition['record_digest'],
                'status' => $crawledPage->getHttpStatusCode() ?? 200,
                'filename' => 'data.warc.gz'
            ];

            $jsonString = json_encode($jsonBlock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonString === false) {
                $this->logger->warning('Failed to encode CDXJ JSON block', [
                    'url' => $url,
                    'json_error' => json_last_error_msg()
                ]);
                continue;
            }

            // Create CDXJ line: SURT timestamp JSON
            $cdxjLine = sprintf(
                "%s %s %s\n",
                $surt,
                $timestamp,
                $jsonString
            );

            fwrite($cdxFile, $cdxjLine);
        }

        fclose($cdxFile);
    }

    private function createSurt(string $url): string
    {
        $parsedUrl = parse_url(strtolower($url));
        $host = $parsedUrl['host'] ?? '';
        $path = $parsedUrl['path'] ?? '/';
        $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        // Reverse host parts and join with commas
        $hostParts = array_reverse(explode('.', $host));
        $surt = implode(',', $hostParts) . ')' . $path . $query . $fragment;

        return $surt;
    }
}