<?php

namespace App\Tests\Unit;

use App\Entity\CrawledPage;
use App\Entity\WaczRequest;
use App\Service\ContentTypeService;
use App\Service\UrlNormalizerService;
use App\Service\Wacz\DatapackageService;
use App\Service\Wacz\PagesJsonlService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WaczFormatUnitTest extends TestCase
{
    public function testDatapackageJsonGeneration(): void
    {
        $logger = new NullLogger();
        $datapackageService = new DatapackageService($logger, 'Test WACZ Generator', '1.1.1');

        $waczRequest = new WaczRequest();
        $waczRequest->setUrl('https://example.com');
        $waczRequest->setTitle('Test Archive');
        $waczRequest->setDescription('Test description');
        $waczRequest->setMaxDepth(1);
        $waczRequest->setMaxPages(10);
        $waczRequest->setCrawlDelay(1000);

        $crawledPages = [];
        $tempDir = sys_get_temp_dir() . '/wacz_unit_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create test files
        mkdir($tempDir . '/archive', 0755, true);
        mkdir($tempDir . '/indexes', 0755, true);
        mkdir($tempDir . '/pages', 0755, true);

        file_put_contents($tempDir . '/archive/data.warc.gz', 'test warc content');
        file_put_contents($tempDir . '/indexes/index.cdx', 'test cdx content');
        file_put_contents($tempDir . '/pages/pages.jsonl', 'test pages content');

        // Generate datapackage.json
        $datapackageService->createDatapackageJson($waczRequest, $crawledPages, $tempDir);

        $datapackagePath = $tempDir . '/datapackage.json';
        $this->assertFileExists($datapackagePath);

        $datapackage = json_decode(file_get_contents($datapackagePath), true);
        $this->assertIsArray($datapackage);

        // Verify required fields
        $this->assertEquals('data-package', $datapackage['profile']);
        $this->assertArrayHasKey('resources', $datapackage);
        $this->assertIsArray($datapackage['resources']);
        $this->assertEquals('Test WACZ Generator', $datapackage['software']);
        $this->assertEquals('1.1.1', $datapackage['wacz_version']);
        $this->assertEquals('Test Archive', $datapackage['title']);

        // Verify resources
        $this->assertCount(3, $datapackage['resources']);

        $resourceNames = array_column($datapackage['resources'], 'name');
        $this->assertContains('pages.jsonl', $resourceNames);
        $this->assertContains('data.warc.gz', $resourceNames);
        $this->assertContains('index.cdx', $resourceNames);

        // Clean up
        $this->removeDirectory($tempDir);
    }

    public function testDatapackageDigestGeneration(): void
    {
        $logger = new NullLogger();
        $datapackageService = new DatapackageService($logger, 'Test WACZ Generator', '1.1.1');

        $tempDir = sys_get_temp_dir() . '/wacz_digest_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create test datapackage.json
        $datapackage = [
            'profile' => 'data-package',
            'resources' => [],
            'wacz_version' => '1.1.1',
            'software' => 'Test WACZ Generator'
        ];

        file_put_contents($tempDir . '/datapackage.json', json_encode($datapackage));

        // Generate digest
        $datapackageService->createDatapackageDigest($tempDir);

        $digestPath = $tempDir . '/datapackage-digest.json';
        $this->assertFileExists($digestPath);

        $digest = json_decode(file_get_contents($digestPath), true);
        $this->assertIsArray($digest);

        // Verify required fields
        $this->assertEquals('datapackage.json', $digest['path']);
        $this->assertStringStartsWith('sha256:', $digest['hash']);
        $this->assertArrayHasKey('signedData', $digest);

        $signedData = $digest['signedData'];
        $this->assertArrayHasKey('hash', $signedData);
        $this->assertArrayHasKey('signature', $signedData);
        $this->assertArrayHasKey('publicKey', $signedData);
        $this->assertArrayHasKey('created', $signedData);
        $this->assertArrayHasKey('software', $signedData);

        // Verify hash matches
        $expectedHash = 'sha256:' . hash_file('sha256', $tempDir . '/datapackage.json');
        $this->assertEquals($expectedHash, $digest['hash']);
        $this->assertEquals($expectedHash, $signedData['hash']);

        // Clean up
        $this->removeDirectory($tempDir);
    }

    public function testPagesJsonlGeneration(): void
    {
        $logger = new NullLogger();
        $urlNormalizer = new UrlNormalizerService($logger);
        $contentTypeService = new ContentTypeService($logger);

        $pagesJsonlService = new PagesJsonlService($logger, $urlNormalizer, $contentTypeService);

        $waczRequest = new WaczRequest();
        $waczRequest->setUrl('https://example.com');

        $crawledPages = [];

        // Create test crawled page
        $page = new CrawledPage();
        $page->setWaczRequest($waczRequest);
        $page->setUrl('https://example.com/test');
        $page->setTitle('Test Page');
        $page->setDepth(0);
        $page->setHttpStatusCode(200);
        $page->setContentType('text/html');
        $page->setContentLength(1024);
        $page->setStatus(CrawledPage::STATUS_SUCCESS);
        $page->setCrawledAt(new \DateTime());
        $crawledPages[] = $page;

        $pageContents = [
            'https://example.com/test' => [
                'content' => '<html><head><title>Test Page</title></head><body><h1>Test</h1><p>Test content</p></body></html>',
                'headers' => ['Content-Type' => ['text/html']],
                'status_code' => 200
            ]
        ];

        $tempDir = sys_get_temp_dir() . '/wacz_pages_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        mkdir($tempDir . '/pages', 0755, true);

        // Generate pages.jsonl
        $pagesJsonlService->createPagesJsonl($crawledPages, $pageContents, $tempDir);

        $pagesPath = $tempDir . '/pages/pages.jsonl';
        $this->assertFileExists($pagesPath);

        $pagesContent = file_get_contents($pagesPath);
        $this->assertNotEmpty($pagesContent);

        $lines = explode("\n", trim($pagesContent));
        $this->assertNotEmpty($lines);
        $this->assertGreaterThanOrEqual(2, count($lines)); // Header + at least one page

        // Verify header record
        $headerLine = json_decode($lines[0], true);
        $this->assertIsArray($headerLine);
        $this->assertEquals('json-pages-1.0', $headerLine['format']);
        $this->assertEquals('pages', $headerLine['id']);
        $this->assertEquals('All Pages', $headerLine['title']);
        $this->assertTrue($headerLine['hasText']);

        // Verify page record
        $pageRecord = json_decode($lines[1], true);
        $this->assertIsArray($pageRecord);

        $requiredFields = ['id', 'url', 'title', 'ts', 'load_state', 'size', 'seed_id', 'text'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $pageRecord);
        }

        $this->assertEquals('https://example.com/test', $pageRecord['url']);
        $this->assertEquals('Test Page', $pageRecord['title']);
        $this->assertEquals(1, $pageRecord['load_state']);
        $this->assertEquals(0, $pageRecord['seed_id']);
        $this->assertStringContainsString('Test content', $pageRecord['text']);

        // Verify timestamp format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $pageRecord['ts']);

        // Clean up
        $this->removeDirectory($tempDir);
    }

    public function testWaczValidationHelpers(): void
    {
        // Test WACZ structure validation
        $this->assertTrue($this->isValidWaczTimestamp('2023-12-01T10:30:45.123Z'));
        $this->assertFalse($this->isValidWaczTimestamp('2023-12-01 10:30:45'));
        $this->assertFalse($this->isValidWaczTimestamp('invalid-timestamp'));

        // Test SURT format validation (simplified)
        $this->assertTrue($this->isValidSurt('com,example)/'));
        $this->assertTrue($this->isValidSurt('com,example)/path'));
        $this->assertFalse($this->isValidSurt(''));

        // Test CDX timestamp format
        $this->assertTrue($this->isValidCdxTimestamp('20231201103045123'));
        $this->assertFalse($this->isValidCdxTimestamp('20231201'));
        $this->assertFalse($this->isValidCdxTimestamp('invalid'));

        // Test hash format
        $this->assertTrue($this->isValidHash('sha256:' . str_repeat('a', 64)));
        $this->assertTrue($this->isValidHash('sha-256:' . str_repeat('b', 64)));
        $this->assertFalse($this->isValidHash('md5:' . str_repeat('c', 32)));
        $this->assertFalse($this->isValidHash('invalid-hash'));
    }

    public function testWaczFilePathValidation(): void
    {
        // Test valid WACZ file paths
        $validPaths = [
            'archive/data.warc.gz',
            'indexes/index.cdx',
            'pages/pages.jsonl',
            'datapackage.json',
            'datapackage-digest.json'
        ];

        foreach ($validPaths as $path) {
            $this->assertTrue($this->isValidWaczPath($path), "Path '{$path}' should be valid");
        }

        // Test invalid paths
        $invalidPaths = [
            '../../../etc/passwd',
            '/absolute/path',
            'path/with/../traversal',
            '',
            'path with spaces.txt'
        ];

        foreach ($invalidPaths as $path) {
            $this->assertFalse($this->isValidWaczPath($path), "Path '{$path}' should be invalid");
        }
    }

    public function testWaczContentValidation(): void
    {
        // Test JSON validation
        $validJson = '{"key": "value", "number": 123}';
        $invalidJson = '{"key": "value", "number": 123';

        $this->assertTrue($this->isValidJson($validJson));
        $this->assertFalse($this->isValidJson($invalidJson));

        // Test URL validation
        $validUrls = [
            'https://example.com',
            'http://test.org/path',
            'https://subdomain.example.com:8080/path?query=value'
        ];

        foreach ($validUrls as $url) {
            $this->assertTrue($this->isValidUrl($url), "URL '{$url}' should be valid");
        }

        $invalidUrls = [
            'not-a-url',
            'ftp://example.com',
            'javascript:alert(1)',
            ''
        ];

        foreach ($invalidUrls as $url) {
            $this->assertFalse($this->isValidUrl($url), "URL '{$url}' should be invalid");
        }
    }

    // Helper methods for validation

    private function isValidWaczTimestamp(string $timestamp): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $timestamp) === 1;
    }

    private function isValidSurt(string $surt): bool
    {
        return !empty($surt) && strlen($surt) > 0;
    }

    private function isValidCdxTimestamp(string $timestamp): bool
    {
        return preg_match('/^\d{17}$/', $timestamp) === 1;
    }

    private function isValidHash(string $hash): bool
    {
        return preg_match('/^sha(-)?256:[a-f0-9]{64}$/', $hash) === 1;
    }

    private function isValidWaczPath(string $path): bool
    {
        // Check for path traversal and other security issues
        if (strpos($path, '..') !== false) {
            return false;
        }

        if (strpos($path, '/') === 0) {
            return false; // No absolute paths
        }

        if (empty($path)) {
            return false;
        }

        if (strpos($path, ' ') !== false) {
            return false; // No spaces for simplicity
        }

        return true;
    }

    private function isValidJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function isValidUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        return in_array($parsed['scheme'], ['http', 'https']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
    }
}
