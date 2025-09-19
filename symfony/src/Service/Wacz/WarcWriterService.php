<?php

namespace App\Service\Wacz;

use App\Entity\CrawledPage;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class WarcWriterService
{
    private array $warcRecordPositions = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $waczSoftwareName
    ) {
    }

    public function createWarcFiles(array $crawledPages, array $pageContents, string $tempDir): array
    {
        $warcFiles = [];
        $warcFilePath = $tempDir . '/archive/data.warc.gz';
        $warcFile = gzopen($warcFilePath, 'wb');

        if (!$warcFile) {
            throw new \Exception('Could not create WARC file');
        }

        // Track WARC record positions for CDXJ index
        $this->warcRecordPositions = [];
        $currentPosition = 0;

        // Write WARC info record
        $infoRecordLength = $this->writeWarcInfoRecord($warcFile);
        $currentPosition += $infoRecordLength;

        // Write WARC records for each page
        foreach ($crawledPages as $crawledPage) {
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());

            if (!$crawledPage->isSuccessful() || !isset($pageContents[$normalizedUrl])) {
                continue;
            }

            $result = $this->writeWarcResponseRecord($warcFile, $crawledPage, $pageContents[$normalizedUrl], $currentPosition);
            $currentPosition += $result['length'];
        }

        gzclose($warcFile);
        $warcFiles[] = 'data.warc.gz';

        return $warcFiles;
    }

    public function getWarcRecordPositions(): array
    {
        return $this->warcRecordPositions;
    }

    private function writeWarcInfoRecord($warcFile): int
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $recordId = '<urn:uuid:' . $this->generateUuid() . '>';

        $payload = "software: " . $this->waczSoftwareName . "\n";
        $payload .= "created: {$timestamp}\n";
        $payload .= "operator: WACZ Generator\n";
        $payload .= "format: WARC File Format 1.1\n";

        $contentLength = strlen($payload);

        $header = "WARC/1.1\r\n";
        $header .= "WARC-Type: warcinfo\r\n";
        $header .= "WARC-Date: {$timestamp}\r\n";
        $header .= "WARC-Record-ID: {$recordId}\r\n";
        $header .= "Content-Type: application/warc-fields\r\n";
        $header .= "Content-Length: {$contentLength}\r\n";
        $header .= "\r\n";

        $fullRecord = $header . $payload . "\r\n\r\n";
        gzwrite($warcFile, $fullRecord);

        return strlen($fullRecord);
    }

    private function writeWarcResponseRecord($warcFile, CrawledPage $crawledPage, array $pageData, int $offset): array
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $timestamp = $crawledPage->getCrawledAt()->format('Y-m-d\TH:i:s\Z');
        $recordId = '<urn:uuid:' . $this->generateUuid() . '>';

        $httpResponse = "HTTP/1.1 {$crawledPage->getHttpStatusCode()} OK\r\n";
        
        $headers = $pageData['headers'] ?? [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $httpResponse .= "{$name}: {$value}\r\n";
            }
        }

        $httpResponse .= "\r\n" . ($pageData['content'] ?? '');
        $contentLength = strlen($httpResponse);

        // WARC header
        $header = "WARC/1.1\r\n";
        $header .= "WARC-Type: response\r\n";
        $header .= "WARC-Date: {$timestamp}\r\n";
        $header .= "WARC-Record-ID: {$recordId}\r\n";
        $header .= "WARC-Target-URI: {$crawledPage->getUrl()}\r\n";
        $header .= "Content-Type: application/http; msgtype=response\r\n";
        $header .= "Content-Length: {$contentLength}\r\n";
        $header .= "\r\n";

        $fullRecord = $header . $httpResponse . "\r\n\r\n";
        gzwrite($warcFile, $fullRecord);

        // Store HTTP payload length for CDXJ separately
        $this->warcRecordPositions[$normalizedUrl] = [
            'offset' => $offset,
            'length' => $contentLength,
            'record_digest' => 'sha256:' . hash('sha256', $fullRecord)
        ];

        return [
            'length' => strlen($fullRecord),
            'record_digest' => 'sha256:' . hash('sha256', $fullRecord)
        ];
    }

    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}