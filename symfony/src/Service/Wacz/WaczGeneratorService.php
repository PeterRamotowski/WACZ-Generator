<?php

namespace App\Service\Wacz;

use App\DTO\WaczGenerationRequestDTO;
use App\Entity\WaczRequest;
use App\Repository\CrawledPageRepository;
use App\Repository\WaczRequestRepository;
use App\Service\Crawler\WebCrawlerService;
use App\Service\Crawler\UserAgentService;
use App\Service\UrlNormalizerService;
use App\Service\ContentTypeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Translation\TranslatorInterface;

class WaczGeneratorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WaczRequestRepository $waczRequestRepository,
        private readonly CrawledPageRepository $crawledPageRepository,
        private readonly WebCrawlerService $webCrawlerService,
        private readonly WaczArchiveService $waczArchiveService,
        private readonly UserAgentService $userAgentService,
        private readonly UrlNormalizerService $urlNormalizer,
        private readonly ContentTypeService $contentType,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $waczOutputDir,
        private readonly int $waczMaxFileSize,
        private readonly string $waczVersion,
        private readonly string $waczSoftwareName
    ) {
    }

    public function createWaczRequest(WaczGenerationRequestDTO $dto): WaczRequest
    {
        $randomUserAgent = $this->userAgentService->getRandomUserAgent();

        $waczRequest = new WaczRequest();
        $waczRequest->setUrl($dto->getNormalizedUrl());
        $waczRequest->setTitle($dto->getTitle());
        $waczRequest->setDescription($dto->getDescription());
        $waczRequest->setMaxDepth($dto->getMaxDepth());
        $waczRequest->setMaxPages($dto->getMaxPages());
        $waczRequest->setCrawlDelay($dto->getCrawlDelay());
        $waczRequest->setMetadata([
            'options' => $dto->toArray(),
            'created_by' => $this->waczSoftwareName,
            'version' => $this->waczVersion,
            'user_agent' => $randomUserAgent
        ]);

        $this->waczRequestRepository->save($waczRequest, true);

        return $waczRequest;
    }

    public function processWaczRequest(WaczRequest $waczRequest): bool
    {
        try {
            $waczRequest->setStatus(WaczRequest::STATUS_PROCESSING);
            $waczRequest->setStartedAt(new \DateTime());
            $this->waczRequestRepository->save($waczRequest, true);

            $metadata = $waczRequest->getMetadata();
            $userAgent = $metadata['user_agent'] ?? $this->userAgentService->getRandomUserAgent();

            $this->webCrawlerService->initHttpClient($userAgent);
            $crawledPages = $this->webCrawlerService->crawlWebsite($waczRequest);

            if (empty($crawledPages)) {
                throw new \Exception('Failed to retrieve any pages from the provided URL');
            }

            $pageContents = $this->webCrawlerService->getPageContents();

            $waczFilePath = $this->waczArchiveService->createWaczArchive($waczRequest, $crawledPages, $pageContents);

            $this->webCrawlerService->clearPageContents();

            if (!$waczFilePath || !file_exists($waczFilePath)) {
                throw new \Exception('Failed to create WACZ archive file');
            }

            $fileSize = filesize($waczFilePath);
            $waczRequest->setStatus(WaczRequest::STATUS_COMPLETED);
            $waczRequest->setCompletedAt(new \DateTime());
            $waczRequest->setFilePath($waczFilePath);
            $waczRequest->setFileSize($fileSize);

            $this->waczRequestRepository->save($waczRequest, true);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('WACZ generation failed', [
                'request_id' => $waczRequest->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $waczRequest->setStatus(WaczRequest::STATUS_FAILED);
            $waczRequest->setErrorMessage($e->getMessage());
            $waczRequest->setCompletedAt(new \DateTime());

            $this->waczRequestRepository->save($waczRequest, true);

            return false;
        }
    }

    public function getWaczRequestById(int $id): ?WaczRequest
    {
        return $this->waczRequestRepository->find($id);
    }

    public function getPendingRequests(): array
    {
        return $this->waczRequestRepository->findPendingRequests();
    }

    public function getCompletedRequests(): array
    {
        return $this->waczRequestRepository->findCompletedRequests();
    }

    public function getFailedRequests(): array
    {
        return $this->waczRequestRepository->findFailedRequests();
    }

    public function getRecentRequests(): array
    {
        return $this->waczRequestRepository->findRecentRequests();
    }

    public function getStatistics(): array
    {
        return $this->waczRequestRepository->getStatistics();
    }

    public function deleteWaczRequest(WaczRequest $waczRequest): bool
    {
        try {
            if ($waczRequest->getFilePath() && file_exists($waczRequest->getFilePath())) {
                unlink($waczRequest->getFilePath());
            }

            foreach ($waczRequest->getCrawledPages() as $crawledPage) {
                $this->crawledPageRepository->remove($crawledPage);
            }

            $this->waczRequestRepository->remove($waczRequest, true);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete WACZ request', [
                'request_id' => $waczRequest->getId(),
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function cleanupOldRequests(\DateTimeInterface $before): int
    {
        $oldRequests = $this->waczRequestRepository->findOldCompletedRequests($before);
        $deleted = 0;

        foreach ($oldRequests as $request) {
            if ($this->deleteWaczRequest($request)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function deleteAllRequests(): int
    {
        $allRequests = $this->waczRequestRepository->findAll();
        $deleted = 0;

        foreach ($allRequests as $request) {
            if ($this->deleteWaczRequest($request)) {
                $deleted++;
            }
        }

        $this->deleteAllWaczFiles();

        return $deleted;
    }

    private function deleteAllWaczFiles(): int
    {
        $filesDeleted = 0;
        $files = glob($this->waczOutputDir . '/*.wacz');

        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $filesDeleted++;
            }
        }

        return $filesDeleted;
    }

    public function validateWaczRequest(WaczGenerationRequestDTO $dto): array
    {
        $errors = [];

        try {
            $randomUserAgent = $this->userAgentService->getRandomUserAgent();
            
            $client = HttpClient::create([
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => $randomUserAgent,
                ],
            ]);

            $response = $client->request('HEAD', $dto->getNormalizedUrl());
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $errors[] = $this->translator->trans('messages.url_not_available', ['%code%' => $statusCode]);
            }

        } catch (\Exception $e) {
            $errors[] = $this->translator->trans('messages.cannot_connect_to_url', ['%error%' => $e->getMessage()]);
        }

        $availableSpace = disk_free_space($this->waczOutputDir);
        if ($availableSpace < $this->waczMaxFileSize) {
            $errors[] = $this->translator->trans('messages.insufficient_disk_space');
        }

        return $errors;
    }

    public function getWaczFileResponse(WaczRequest $waczRequest): ?\SplFileInfo
    {
        if (!$waczRequest->isCompleted() || !$waczRequest->getFilePath()) {
            return null;
        }

        $filePath = $waczRequest->getFilePath();
        
        if (!file_exists($filePath)) {
            return null;
        }

        return new \SplFileInfo($filePath);
    }

    public function getWaczRequestProgress(WaczRequest $waczRequest): array
    {
        $stats = $this->crawledPageRepository->getStatistics($waczRequest);
        
        $progress = [
            'status' => $waczRequest->getStatus(),
            'total_pages' => $stats['total'],
            'successful_pages' => $stats['success'],
            'error_pages' => $stats['error'],
            'skipped_pages' => $stats['skipped'],
            'progress_percentage' => 0,
            'started_at' => $waczRequest->getStartedAt()?->format('Y-m-d H:i:s'),
            'estimated_completion' => null,
        ];

        if ($stats['total'] > 0) {
            $progress['progress_percentage'] = min(100, ($stats['total'] / $waczRequest->getMaxPages()) * 100);
            
            if ($waczRequest->isProcessing() && $waczRequest->getStartedAt() && $stats['total'] > 0) {
                $elapsed = time() - $waczRequest->getStartedAt()->getTimestamp();
                $averageTimePerPage = $elapsed / $stats['total'];
                $remainingPages = max(0, $waczRequest->getMaxPages() - $stats['total']);
                $estimatedRemainingTime = $remainingPages * $averageTimePerPage;
                
                $progress['estimated_completion'] = (new \DateTime())
                    ->add(new \DateInterval('PT' . (int)$estimatedRemainingTime . 'S'))
                    ->format('Y-m-d H:i:s');
            }
        }

        return $progress;
    }

    public function getCrawledPages(WaczRequest $waczRequest, int $limit = null, int $offset = 0): array
    {
        return $this->crawledPageRepository->findByWaczRequest($waczRequest, $limit, $offset);
    }
}
