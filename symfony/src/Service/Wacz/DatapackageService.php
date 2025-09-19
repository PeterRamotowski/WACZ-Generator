<?php

namespace App\Service\Wacz;

use App\Entity\WaczRequest;
use Psr\Log\LoggerInterface;

class DatapackageService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $waczSoftwareName,
        private readonly string $waczVersion
    ) {
    }

    public function createDatapackageJson(WaczRequest $waczRequest, array $crawledPages, string $tempDir): void
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
            'wacz_version' => $this->waczVersion,
            'software' => $this->waczSoftwareName,
            'created' => $waczRequest->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'title' => $waczRequest->getTitle(),
            'modified' => (new \DateTime())->format('Y-m-d\TH:i:s.000\Z')
        ];

        $jsonFilePath = $tempDir . '/datapackage.json';
        file_put_contents($jsonFilePath, json_encode($datapackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function createDatapackageDigest(string $tempDir): void
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
                'software' => $this->waczSoftwareName
            ]
        ];

        file_put_contents($tempDir . '/datapackage-digest.json', json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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