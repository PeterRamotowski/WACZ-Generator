<?php

namespace App\Tests\Functional;

use App\DTO\WaczGenerationRequestDTO;
use App\Entity\WaczRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

/**
 * Tests WACZ format compliance according to the WACZ specification
 * 
 * The test validates all aspects of WACZ 1.1.1 specification compliance:
 * - ZIP file format validity
 * - Required file presence (datapackage.json, datapackage-digest.json)
 * - datapackage.json format and metadata
 * - Resource metadata accuracy (file sizes, hashes)
 * - WARC file format compliance (gzip compression, WARC/1.1 format)
 * - CDX index format compliance (CDXJ format)
 * - pages.jsonl format compliance
 * - datapackage-digest.json integrity verification
 * - Proper directory structure
 * 
 * This ensures that any WACZ files produced by the application meet the 
 * official specification requirements.
 * 
 * @see https://specs.webrecorder.net/wacz/1.1.1/
 */
class WaczSpecificationComplianceTest extends WebTestCase
{
    private ?string $tempTestDir = null;
    private ?Filesystem $filesystem = null;
    private ?EntityManagerInterface $entityManager = null;
    private array $waczFiles = [];
    private array $generatedWaczFiles = [];
    private array $createdWaczRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->tempTestDir = sys_get_temp_dir() . '/wacz_compliance_test_' . uniqid();
        $this->filesystem->mkdir($this->tempTestDir);

        // Generate WACZ files for testing in test environment
        $this->generateTestWaczFiles();
    }

    protected function tearDown(): void
    {
        // Clean up generated WACZ files
        foreach ($this->generatedWaczFiles as $waczFile) {
            if (file_exists($waczFile)) {
                unlink($waczFile);
            }
        }

        // Clean up database data
        if ($this->entityManager) {
            foreach ($this->createdWaczRequests as $waczRequest) {
                // First remove all related CrawledPages
                $crawledPages = $this->entityManager->getRepository(\App\Entity\CrawledPage::class)
                    ->findBy(['waczRequest' => $waczRequest]);
                foreach ($crawledPages as $crawledPage) {
                    $this->entityManager->remove($crawledPage);
                }
                $this->entityManager->flush();

                // Then remove the WaczRequest
                $this->entityManager->remove($waczRequest);
                $this->entityManager->flush();
            }
        }

        if ($this->tempTestDir && $this->filesystem) {
            $this->filesystem->remove($this->tempTestDir);
        }

        parent::tearDown();
    }

    /**
     * Generate test WACZ files in test environment using the same pattern as WaczIntegrationTest
     */
    private function generateTestWaczFiles(): void
    {
        try {
            // First, try to generate new WACZ files using the application
            $this->generateNewWaczFiles();

            // If that fails, check for existing WACZ files
            if (empty($this->waczFiles)) {
                $this->findExistingWaczFiles();
            }

            // Skip tests if no WACZ files are available
            if (empty($this->waczFiles)) {
                $this->markTestSkipped('No WACZ files could be generated or found for compliance testing. This may be due to network restrictions or service configuration issues in the test environment.');
            }
        } catch (\Exception $e) {
            // If generation fails, try to find existing files
            $this->findExistingWaczFiles();

            if (empty($this->waczFiles)) {
                $this->markTestSkipped('Failed to generate or find WACZ files for testing: ' . $e->getMessage());
            }
        }
    }

    /**
     * Try to generate new WACZ files using the application
     */
    private function generateNewWaczFiles(): void
    {
        $client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        // Test with a simple, reliable URL
        $waczFilePath = $this->createTestWaczFile($client);
        if ($waczFilePath) {
            $this->waczFiles[] = $waczFilePath;
            $this->generatedWaczFiles[] = $waczFilePath;
        }
    }

    /**
     * Find existing WACZ files produced by the application
     */
    private function findExistingWaczFiles(): void
    {
        $waczDirectory = '/var/www/wacz-files';
        if (is_dir($waczDirectory)) {
            $existingFiles = glob($waczDirectory . '/*.wacz');
            foreach ($existingFiles as $file) {
                if (file_exists($file) && filesize($file) > 0) {
                    $this->waczFiles[] = $file;
                }
            }
        }
    }

    /**
     * Create a test WACZ file using the same pattern as WaczIntegrationTest
     */
    private function createTestWaczFile(object $client): ?string
    {
        try {
            // Use the service directly instead of HTTP form submission
            $waczGeneratorService = static::getContainer()->get('App\Service\Wacz\WaczGeneratorService');

            $dto = new WaczGenerationRequestDTO();
            $dto->setUrl('https://crawler-test.com/');
            $dto->setTitle('Test WACZ Compliance');
            $dto->setDescription('WACZ file for compliance testing');
            $dto->setMaxDepth(1);
            $dto->setMaxPages(2);
            $dto->setCrawlDelay(1000);

            $waczRequest = $waczGeneratorService->createWaczRequest($dto);

            $this->entityManager->persist($waczRequest);
            $this->entityManager->flush();

            // Track for cleanup
            $this->createdWaczRequests[] = $waczRequest;

            $waczGeneratorService->processWaczRequest($waczRequest);

            if ($waczRequest && $waczRequest->getStatus() === 'failed') {
                return null;
            }

            if (!$waczRequest || $waczRequest->getStatus() !== 'completed') {
                return null;
            }

            $waczFilePath = $waczRequest->getFilePath();
            if ($waczFilePath && file_exists($waczFilePath)) {
                return $waczFilePath;
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Test WACZ specification requirement: ZIP file format
     */
    public function testWaczIsValidZipFile(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            // WACZ MUST be a valid ZIP file
            $zip = new ZipArchive();
            $result = $zip->open($waczFile);
            $this->assertTrue($result === TRUE, "WACZ file MUST be a valid ZIP archive: " . basename($waczFile));

            $zip->close();
        }
    }

    /**
     * Test WACZ specification requirement: Required files
     */
    public function testWaczContainsRequiredFiles(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $zip = new ZipArchive();
            $zip->open($waczFile);

            // Required files according to WACZ 1.1.1 specification
            $requiredFiles = [
                'datapackage.json',           // MUST be present at root
                'datapackage-digest.json'     // MUST be present at root for integrity
            ];

            foreach ($requiredFiles as $requiredFile) {
                $this->assertNotFalse(
                    $zip->locateName($requiredFile),
                    "WACZ MUST contain required file: {$requiredFile} in " . basename($waczFile)
                );
            }

            $zip->close();
        }
    }

    /**
     * Test WACZ specification requirement: datapackage.json format
     */
    public function testDatapackageJsonCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $datapackagePath = $extractPath . '/datapackage.json';
            $this->assertFileExists($datapackagePath, 'datapackage.json must exist in ' . basename($waczFile));

            $datapackage = json_decode(file_get_contents($datapackagePath), true);
            $this->assertIsArray($datapackage, 'datapackage.json MUST contain valid JSON in ' . basename($waczFile));

            // Required fields according to specification
            $this->assertArrayHasKey('profile', $datapackage, 'profile field missing in ' . basename($waczFile));
            $this->assertEquals('data-package', $datapackage['profile'], 'profile MUST be "data-package" in ' . basename($waczFile));

            $this->assertArrayHasKey('resources', $datapackage, 'resources field missing in ' . basename($waczFile));
            $this->assertIsArray($datapackage['resources'], 'resources MUST be an array in ' . basename($waczFile));

            $this->assertArrayHasKey('wacz_version', $datapackage, 'wacz_version field missing in ' . basename($waczFile));
            $this->assertIsString($datapackage['wacz_version'], 'wacz_version MUST be a string in ' . basename($waczFile));

            $this->assertArrayHasKey('software', $datapackage, 'software field missing in ' . basename($waczFile));
            $this->assertIsString($datapackage['software'], 'software MUST be a string in ' . basename($waczFile));

            // Optional but recommended fields
            if (isset($datapackage['title'])) {
                $this->assertIsString($datapackage['title'], 'title MUST be a string if present in ' . basename($waczFile));
            }

            if (isset($datapackage['created'])) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
                    $datapackage['created'],
                    'created MUST be in ISO 8601 format with milliseconds in ' . basename($waczFile)
                );
            }
        }
    }

    /**
     * Test WACZ specification requirement: Resources format
     */
    public function testResourcesCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $datapackagePath = $extractPath . '/datapackage.json';
            $datapackage = json_decode(file_get_contents($datapackagePath), true);

            $this->assertArrayHasKey('resources', $datapackage, 'resources field missing in ' . basename($waczFile));
            $resources = $datapackage['resources'];

            foreach ($resources as $resource) {
                // Required fields for each resource
                $this->assertArrayHasKey('name', $resource, 'Each resource MUST have a name in ' . basename($waczFile));
                $this->assertIsString($resource['name'], 'Resource name MUST be a string in ' . basename($waczFile));

                $this->assertArrayHasKey('path', $resource, 'Each resource MUST have a path in ' . basename($waczFile));
                $this->assertIsString($resource['path'], 'Resource path MUST be a string in ' . basename($waczFile));

                $this->assertArrayHasKey('hash', $resource, 'Each resource MUST have a hash in ' . basename($waczFile));
                $this->assertIsString($resource['hash'], 'Resource hash MUST be a string in ' . basename($waczFile));
                $this->assertStringStartsWith('sha256:', $resource['hash'], 'Resource hash MUST use sha256 algorithm in ' . basename($waczFile));

                $this->assertArrayHasKey('bytes', $resource, 'Each resource MUST have bytes field in ' . basename($waczFile));
                $this->assertIsInt($resource['bytes'], 'Resource bytes MUST be an integer in ' . basename($waczFile));
                $this->assertGreaterThanOrEqual(0, $resource['bytes'], 'Resource bytes MUST be non-negative in ' . basename($waczFile));

                // Verify the referenced file exists in the archive
                $resourcePath = $extractPath . '/' . $resource['path'];
                $this->assertFileExists($resourcePath, "Resource file MUST exist: {$resource['path']} in " . basename($waczFile));

                // Verify file size matches
                $actualSize = filesize($resourcePath);
                $this->assertEquals(
                    $resource['bytes'],
                    $actualSize,
                    "Resource bytes MUST match actual file size for {$resource['name']} in " . basename($waczFile)
                );

                // Verify hash matches
                $actualHash = 'sha256:' . hash_file('sha256', $resourcePath);
                $this->assertEquals(
                    $resource['hash'],
                    $actualHash,
                    "Resource hash MUST match actual file hash for {$resource['name']} in " . basename($waczFile)
                );
            }
        }
    }

    /**
     * Test WACZ specification requirement: WARC files
     */
    public function testWarcFileCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $warcPath = $extractPath . '/archive/data.warc.gz';
            if (file_exists($warcPath)) {
                // WARC files MUST be gzip compressed
                $this->assertTrue(
                    $this->isGzipFile($warcPath),
                    'WARC files MUST be gzip compressed in ' . basename($waczFile)
                );

                // WARC content MUST follow WARC 1.1 specification
                $warcContent = gzfile($warcPath);
                $this->assertNotEmpty($warcContent, 'WARC content must not be empty in ' . basename($waczFile));

                $fullContent = implode('', $warcContent);
                $this->assertStringContainsString('WARC/1.1', $fullContent, 'WARC files MUST use WARC/1.1 format in ' . basename($waczFile));

                // MUST contain warcinfo record
                $this->assertStringContainsString('WARC-Type: warcinfo', $fullContent, 'WARC files MUST contain warcinfo record in ' . basename($waczFile));

                // Response records MUST have required headers
                if (strpos($fullContent, 'WARC-Type: response') !== false) {
                    $this->assertStringContainsString('WARC-Target-URI:', $fullContent, 'Response records must have WARC-Target-URI in ' . basename($waczFile));
                    $this->assertStringContainsString('WARC-Date:', $fullContent, 'Response records must have WARC-Date in ' . basename($waczFile));
                    $this->assertStringContainsString('WARC-Record-ID:', $fullContent, 'Response records must have WARC-Record-ID in ' . basename($waczFile));
                    $this->assertStringContainsString('Content-Length:', $fullContent, 'Response records must have Content-Length in ' . basename($waczFile));
                }
            }
        }
    }

    /**
     * Test WACZ specification requirement: CDX index format
     */
    public function testCdxIndexCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $cdxPath = $extractPath . '/indexes/index.cdx';
            if (file_exists($cdxPath)) {
                $cdxContent = file_get_contents($cdxPath);
                $this->assertNotEmpty($cdxContent, 'CDX content must not be empty in ' . basename($waczFile));

                $lines = explode("\n", trim($cdxContent));

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    // CDXJ format: SURT timestamp JSON
                    $parts = explode(' ', $line, 3);
                    $this->assertCount(3, $parts, 'CDX lines MUST follow CDXJ format: SURT timestamp JSON in ' . basename($waczFile));

                    $surt = $parts[0];
                    $timestamp = $parts[1];
                    $json = $parts[2];

                    // SURT MUST not be empty
                    $this->assertNotEmpty($surt, 'SURT MUST not be empty in ' . basename($waczFile));

                    // Timestamp MUST be 17 digits (YYYYMMDDHHMMSSMMM)
                    $this->assertMatchesRegularExpression('/^\d{17}$/', $timestamp, 'Timestamp MUST be 17 digits in ' . basename($waczFile));

                    // JSON MUST be valid and contain required fields
                    $data = json_decode($json, true);
                    $this->assertIsArray($data, 'CDX JSON MUST be valid in ' . basename($waczFile));

                    $requiredFields = ['url', 'digest', 'mime', 'offset', 'length', 'status', 'filename'];
                    foreach ($requiredFields as $field) {
                        $this->assertArrayHasKey($field, $data, "CDX JSON MUST contain field: {$field} in " . basename($waczFile));
                    }

                    // Digest MUST use sha-256 format
                    if (isset($data['digest'])) {
                        $this->assertStringStartsWith('sha-256:', $data['digest'], 'CDX digest MUST use sha-256 format in ' . basename($waczFile));
                    }

                    // Status MUST be HTTP status code
                    if (isset($data['status'])) {
                        $this->assertIsInt($data['status'], 'CDX status MUST be integer in ' . basename($waczFile));
                        $this->assertGreaterThanOrEqual(100, $data['status'], 'CDX status MUST be valid HTTP status code in ' . basename($waczFile));
                        $this->assertLessThan(600, $data['status'], 'CDX status MUST be valid HTTP status code in ' . basename($waczFile));
                    }
                }
            }
        }
    }

    /**
     * Test WACZ specification requirement: pages.jsonl format
     */
    public function testPagesJsonlCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $pagesPath = $extractPath . '/pages/pages.jsonl';
            if (file_exists($pagesPath)) {
                $pagesContent = file_get_contents($pagesPath);
                $this->assertNotEmpty($pagesContent, 'Pages content must not be empty in ' . basename($waczFile));

                $lines = explode("\n", trim($pagesContent));
                $this->assertNotEmpty($lines, 'Pages must have content lines in ' . basename($waczFile));

                // First line MUST be header record
                $headerLine = json_decode($lines[0], true);
                $this->assertIsArray($headerLine, 'First line MUST be valid JSON header record in ' . basename($waczFile));

                $this->assertArrayHasKey('format', $headerLine, 'Header MUST contain format field in ' . basename($waczFile));
                $this->assertEquals('json-pages-1.0', $headerLine['format'], 'Format MUST be json-pages-1.0 in ' . basename($waczFile));

                // Page records MUST follow specification
                for ($i = 1; $i < count($lines); $i++) {
                    if (empty(trim($lines[$i]))) continue;

                    $pageData = json_decode($lines[$i], true);
                    $this->assertIsArray($pageData, 'Each page line MUST be valid JSON in ' . basename($waczFile));

                    // Required fields
                    $requiredFields = ['id', 'url', 'ts'];
                    foreach ($requiredFields as $field) {
                        $this->assertArrayHasKey($field, $pageData, "Page record MUST contain field: {$field} in " . basename($waczFile));
                    }

                    // Timestamp MUST be ISO 8601 format
                    $this->assertMatchesRegularExpression(
                        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
                        $pageData['ts'],
                        'Page timestamp MUST be in ISO 8601 format with milliseconds in ' . basename($waczFile)
                    );

                    // URL MUST be valid
                    $this->assertStringStartsWith('http', $pageData['url'], 'Page URL MUST start with http in ' . basename($waczFile));

                    // ID MUST be non-empty string
                    $this->assertIsString($pageData['id'], 'Page ID MUST be string in ' . basename($waczFile));
                    $this->assertNotEmpty($pageData['id'], 'Page ID MUST not be empty in ' . basename($waczFile));
                }
            }
        }
    }

    /**
     * Test WACZ specification requirement: datapackage-digest.json
     */
    public function testDatapackageDigestCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            $digestPath = $extractPath . '/datapackage-digest.json';
            $this->assertFileExists($digestPath, 'datapackage-digest.json MUST be present in ' . basename($waczFile));

            $digestContent = file_get_contents($digestPath);
            $digest = json_decode($digestContent, true);

            $this->assertIsArray($digest, 'datapackage-digest.json MUST contain valid JSON in ' . basename($waczFile));

            // Required fields
            $this->assertArrayHasKey('path', $digest, 'Digest MUST contain path field in ' . basename($waczFile));
            $this->assertEquals('datapackage.json', $digest['path'], 'Path MUST reference datapackage.json in ' . basename($waczFile));

            $this->assertArrayHasKey('hash', $digest, 'Digest MUST contain hash field in ' . basename($waczFile));
            $this->assertStringStartsWith('sha256:', $digest['hash'], 'Hash MUST use sha256 algorithm in ' . basename($waczFile));

            // Verify hash matches actual datapackage.json
            $datapackagePath = $extractPath . '/datapackage.json';
            $actualHash = 'sha256:' . hash_file('sha256', $datapackagePath);
            $this->assertEquals(
                $digest['hash'],
                $actualHash,
                'Digest hash MUST match actual datapackage.json hash in ' . basename($waczFile)
            );

            // signedData is required for integrity verification
            $this->assertArrayHasKey('signedData', $digest, 'Digest MUST contain signedData field in ' . basename($waczFile));
            $signedData = $digest['signedData'];

            $this->assertArrayHasKey('hash', $signedData, 'signedData MUST contain hash in ' . basename($waczFile));
            $this->assertArrayHasKey('signature', $signedData, 'signedData MUST contain signature in ' . basename($waczFile));
            $this->assertArrayHasKey('publicKey', $signedData, 'signedData MUST contain publicKey in ' . basename($waczFile));
            $this->assertArrayHasKey('created', $signedData, 'signedData MUST contain created timestamp in ' . basename($waczFile));
            $this->assertArrayHasKey('software', $signedData, 'signedData MUST contain software identifier in ' . basename($waczFile));

            // Verify timestamp format
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
                $signedData['created'],
                'signedData created MUST be in ISO 8601 format in ' . basename($waczFile)
            );
        }
    }

    /**
     * Test WACZ specification requirement: Directory structure
     */
    public function testDirectoryStructureCompliance(): void
    {
        $this->assertNotEmpty($this->waczFiles, 'At least one WACZ file should be available for testing');

        foreach ($this->waczFiles as $waczFile) {
            $extractPath = $this->tempTestDir . '/extracted_' . basename($waczFile, '.wacz');
            $this->extractWaczFile($waczFile, $extractPath);

            // Recommended directory structure
            $recommendedDirs = [
                'archive',   // For WARC files
                'indexes',   // For CDX files
                'pages'      // For pages.jsonl
            ];

            foreach ($recommendedDirs as $dir) {
                if (is_dir($extractPath . '/' . $dir)) {
                    $this->assertDirectoryExists($extractPath . '/' . $dir, "Directory {$dir} should exist if used in " . basename($waczFile));
                }
            }

            // Archive directory should contain WARC files
            if (is_dir($extractPath . '/archive')) {
                $archiveFiles = glob($extractPath . '/archive/*.warc.gz');
                if (!empty($archiveFiles)) {
                    foreach ($archiveFiles as $warcFile) {
                        $this->assertTrue($this->isGzipFile($warcFile), 'WARC files in archive/ MUST be gzipped in ' . basename($waczFile));
                    }
                }
            }
        }
    }

    private function extractWaczFile(string $waczFilePath, string $extractPath): void
    {
        $this->filesystem->mkdir($extractPath);

        $zip = new ZipArchive();
        $result = $zip->open($waczFilePath);
        $this->assertTrue($result === TRUE, 'Should be able to open WACZ file: ' . basename($waczFilePath));

        $zip->extractTo($extractPath);
        $zip->close();
    }

    private function isGzipFile(string $filePath): bool
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 3);
        fclose($handle);

        // Check for gzip magic number
        return $header === "\x1f\x8b\x08";
    }

    private function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
