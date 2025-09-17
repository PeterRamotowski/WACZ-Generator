<?php

namespace App\Service\Wacz;

use App\Entity\WaczRequest;
use Psr\Log\LoggerInterface;

class WaczZipService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $waczOutputDir
    ) {
        if (!is_dir($this->waczOutputDir)) {
            mkdir($this->waczOutputDir, 0755, true);
        }
    }

    public function createZipArchive(WaczRequest $waczRequest, string $tempDir): string
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

    public function cleanupTempDirectory(string $tempDir): void
    {
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    public function createWaczDirectoryStructure(string $tempDir): void
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
}