<?php

namespace App\Service;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use App\Service\ContentTypeService;
use App\Service\UrlNormalizerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Uid\Uuid;

class WaczArchiveService
{
    private const SOFTWARE_NAME = 'WACZ-Generator/1.0';
    
    private array $pageContents = [];
    private array $warcRecordPositions = [];
    
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentType,
        private readonly string $waczTempDir,
        private readonly string $waczOutputDir
    ) {
        if (!is_dir($this->waczTempDir)) {
            mkdir($this->waczTempDir, 0755, true);
        }
        if (!is_dir($this->waczOutputDir)) {
            mkdir($this->waczOutputDir, 0755, true);
        }
    }

    public function createWaczArchive(WaczRequest $waczRequest, array $crawledPages, array $pageContents = []): string
    {
        $this->pageContents = $pageContents;

        $requestId = $waczRequest->getId();
        $tempDir = $this->waczTempDir . '/wacz_' . $requestId;
        $tempDir = $this->waczTempDir . '/wacz_' . $requestId;

        $this->createWaczDirectoryStructure($tempDir);

        try {
            $this->downloadPageContents($crawledPages, $tempDir);
            $this->createWarcFiles($crawledPages, $tempDir);
            $this->createCdxIndex($crawledPages, $tempDir);
            $this->createPagesJsonl($crawledPages, $tempDir);
            $this->createDatapackageJson($waczRequest, $crawledPages, $tempDir);
            $this->createDatapackageDigest($tempDir);
            $waczFilePath = $this->createZipArchive($waczRequest, $tempDir);
            $this->cleanupTempDirectory($tempDir);
            return $waczFilePath;
        } catch (\Exception $e) {
            $this->cleanupTempDirectory($tempDir);
            throw $e;
        }
    }

    private function createWaczDirectoryStructure(string $tempDir): void
    {
        $directories = [
            $tempDir,
            $tempDir . '/archive',
            $tempDir . '/indexes',
            $tempDir . '/pages'
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function downloadPageContents(array $crawledPages, string $tempDir): void
    {
        foreach ($crawledPages as $crawledPage) {
            if (!$crawledPage->isSuccessful()) {
                continue;
            }

            $url = $crawledPage->getUrl();
            $normalizedUrl = $this->urlNormalizer->normalizeUrl($url);

            // Use already crawled content if available
            if (isset($this->pageContents[$normalizedUrl]) && is_string($this->pageContents[$normalizedUrl])) {
                // Convert to expected format
                $content = $this->pageContents[$normalizedUrl];
                $this->pageContents[$normalizedUrl] = [
                    'content' => $content,
                    'headers' => ['content-type' => [$crawledPage->getContentType()]],
                    'status_code' => $crawledPage->getHttpStatusCode()
                ];
                continue;
            }

            // Use content from database if available
            if ($crawledPage->getContent()) {
                $this->pageContents[$normalizedUrl] = [
                    'content' => $crawledPage->getContent(),
                    'headers' => $crawledPage->getHeaders() ?? ['content-type' => [$crawledPage->getContentType()]],
                    'status_code' => $crawledPage->getHttpStatusCode()
                ];
                continue;
            }

            // For binary files or missing content, download if needed
            if (!$this->contentType->isTextContent($crawledPage->getContentType()) || !isset($this->pageContents[$normalizedUrl])) {
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
    }

    private function createWarcFiles(array $crawledPages, string $tempDir): array
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
            if (!$crawledPage->isSuccessful() || !isset($this->pageContents[$normalizedUrl])) {
                continue;
            }

            $result = $this->writeWarcResponseRecord($warcFile, $crawledPage, $currentPosition);
            $currentPosition += $result['length'];
        }

        gzclose($warcFile);
        $warcFiles[] = 'data.warc.gz';

        return $warcFiles;
    }

    private function writeWarcInfoRecord($warcFile): int
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $recordId = '<urn:uuid:' . $this->generateUuid() . '>';

        $payload = "software: " . self::SOFTWARE_NAME . "\n";
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

    private function writeWarcResponseRecord($warcFile, CrawledPage $crawledPage, int $offset): array
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $pageData = $this->pageContents[$normalizedUrl];
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

    private function createCdxIndex(array $crawledPages, string $tempDir): void
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
            $content = $this->pageContents[$normalizedUrl]['content'] ?? '';
            
            // Get real WARC record position
            $recordPosition = $this->warcRecordPositions[$normalizedUrl] ?? null;
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

    private function createPagesJsonl(array $crawledPages, string $tempDir): void
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
            $pageContent = $this->pageContents[$normalizedUrl] ?? null;
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

    private function createDatapackageJson(WaczRequest $waczRequest, array $crawledPages, string $tempDir): void
    {
        $warcFile = $tempDir . '/archive/data.warc.gz';
        $cdxFile = $tempDir . '/indexes/index.cdx';
        $pagesFile = $tempDir . '/pages/pages.jsonl';
        
        $warcSize = file_exists($warcFile) ? filesize($warcFile) : 0;
        $cdxSize = file_exists($cdxFile) ? filesize($cdxFile) : 0;
        $pagesSize = file_exists($pagesFile) ? filesize($pagesFile) : 0;
        
        $warcHash = file_exists($warcFile) ? 'sha256:' . hash_file('sha256', $warcFile) : '';
        $cdxHash = file_exists($cdxFile) ? 'sha256:' . hash_file('sha256', $cdxFile) : '';
        $pagesHash = file_exists($pagesFile) ? 'sha256:' . hash_file('sha256', $pagesFile) : '';
        
        $datapackage = [
            'profile' => 'data-package',
            'resources' => [
                [
                    'name' => 'pages.jsonl',
                    'path' => 'pages/pages.jsonl',
                    'hash' => $pagesHash,
                    'bytes' => $pagesSize
                ],
                [
                    'name' => 'data.warc.gz', 
                    'path' => 'archive/data.warc.gz',
                    'hash' => $warcHash,
                    'bytes' => $warcSize
                ],
                [
                    'name' => 'index.cdx',
                    'path' => 'indexes/index.cdx', 
                    'hash' => $cdxHash,
                    'bytes' => $cdxSize
                ]
            ],
            'wacz_version' => '1.1.1',
            'software' => self::SOFTWARE_NAME,
            'created' => $waczRequest->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'title' => $waczRequest->getTitle(),
            'modified' => (new \DateTime())->format('Y-m-d\TH:i:s.000\Z')
        ];

        $jsonFilePath = $tempDir . '/datapackage.json';
        file_put_contents($jsonFilePath, json_encode($datapackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function createDatapackageDigest(string $tempDir): void
    {
        $datapackageFile = $tempDir . '/datapackage.json';
        if (!file_exists($datapackageFile)) {
            throw new \Exception('datapackage.json file not found');
        }

        $datapackageHash = 'sha256:' . hash_file('sha256', $datapackageFile);
        $keyPair = $this->generateKeyPair();
        $signature = $this->signData($datapackageHash, $keyPair['private']);

        $digest = [
            'path' => 'datapackage.json',
            'hash' => $datapackageHash,
            'signedData' => [
                'hash' => $datapackageHash,
                'signature' => $signature,
                'publicKey' => $keyPair['public'],
                'created' => (new \DateTime())->format('Y-m-d\TH:i:s.v\Z'),
                'software' => self::SOFTWARE_NAME
            ]
        ];

        file_put_contents($tempDir . '/datapackage-digest.json', json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function createZipArchive(WaczRequest $waczRequest, string $tempDir): string
    {
        $timestamp = (new \DateTime())->format('Y-m-d_H-i-s');
        $sanitizedTitle = preg_replace('/[^a-zA-Z0-9-_]/', '_', $waczRequest->getTitle());
        $filename = sprintf('wacz_%s_%s_%d.wacz', $sanitizedTitle, $timestamp, $waczRequest->getId());
        $zipFilePath = $this->waczOutputDir . '/' . $filename;

        $zip = new \ZipArchive();
        $result = $zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new \Exception("Cannot create ZIP archive: {$result}");
        }

        $this->addDirectoryToZip($zip, $tempDir, '');

        $zip->close();

        return $zipFilePath;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $zipPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = $zipPath . substr($filePath, strlen($dirPath) + 1);

            if ($file->isFile()) {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    private function cleanupTempDirectory(string $tempDir): void
    {
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($dir);
    }

    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    public function storePageContent(CrawledPage $crawledPage, string $content): void
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        $this->pageContents[$normalizedUrl] = $content;
    }

    public function getStoredPageContent(CrawledPage $crawledPage): ?string
    {
        $normalizedUrl = $this->urlNormalizer->normalizeUrl($crawledPage->getUrl());
        return $this->pageContents[$normalizedUrl] ?? null;
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

    /**
     * Generate ECDSA key pair for signing
     */
    private function generateKeyPair(): array
    {
        // Use ECDSA with P-384 curve (secp384r1) - minimum required by OpenSSL
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1', // P-384 (384 bits)
            'private_key_bits' => 384,
        ];

        $keyResource = openssl_pkey_new($config);
        if (!$keyResource) {
            throw new \Exception('Failed to generate key pair: ' . openssl_error_string());
        }

        // Get private key in PEM format
        openssl_pkey_export($keyResource, $privateKey);

        // Get public key details
        $keyDetails = openssl_pkey_get_details($keyResource);
        if (!$keyDetails) {
            throw new \Exception('Failed to get key details: ' . openssl_error_string());
        }

        // Export public key in DER format and encode to base64
        $publicKeyPem = $keyDetails['key'];
        
        // Convert PEM to DER for public key
        $publicKeyDer = '';
        $tempFile = tempnam(sys_get_temp_dir(), 'pubkey_');
        file_put_contents($tempFile, $publicKeyPem);
        
        // Use openssl command to convert PEM to DER
        $derFile = tempnam(sys_get_temp_dir(), 'pubkey_der_');
        exec("openssl pkey -pubin -in {$tempFile} -outform DER -out {$derFile} 2>/dev/null", $output, $return_var);
        
        if ($return_var === 0 && file_exists($derFile)) {
            $publicKeyDer = file_get_contents($derFile);
            unlink($derFile);
        }
        unlink($tempFile);
        
        // Fallback: extract from PEM manually if openssl command failed
        if (empty($publicKeyDer)) {
            $publicKeyDer = base64_decode(
                str_replace(
                    ["\r", "\n", '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', ' '],
                    '',
                    $publicKeyPem
                )
            );
        }

        return [
            'private' => $keyResource,
            'public' => base64_encode($publicKeyDer)
        ];
    }

    /**
     * Sign data using ECDSA
     */
    private function signData(string $data, $privateKey): string
    {
        $signature = '';
        $success = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            throw new \Exception('Failed to sign data: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }
}
