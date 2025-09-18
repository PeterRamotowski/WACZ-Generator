<?php

namespace App\MessageHandler;

use App\Entity\WaczRequest;
use App\Message\ProcessWaczMessage;
use App\Service\Wacz\WaczGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
class ProcessWaczMessageHandler
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(ProcessWaczMessage $message): void
    {
        $waczRequestId = $message->getWaczRequestId();

        $this->entityManager->clear();

        $waczRequest = $this->waczGeneratorService->getWaczRequestById($waczRequestId);

        if (!$waczRequest) {
            $this->logger->error('WACZ request not found', [
                'wacz_request_id' => $waczRequestId
            ]);
            return;
        }

        // Check if the request is in a processable state
        $canProcess = false;
        $currentStatus = $waczRequest->getStatus();

        if ($currentStatus === WaczRequest::STATUS_PENDING) {
            $canProcess = true;
        } elseif ($currentStatus === WaczRequest::STATUS_PROCESSING) {
            // Check if this is a stuck request
            $stuckThreshold = new \DateTime('-30 minutes');
            $startedAt = $waczRequest->getStartedAt();

            if (!$startedAt || $startedAt < $stuckThreshold) {
                $this->logger->warning('Found stuck WACZ request, will retry processing', [
                    'wacz_request_id' => $waczRequestId,
                    'started_at' => $startedAt?->format('Y-m-d H:i:s'),
                    'stuck_threshold' => $stuckThreshold->format('Y-m-d H:i:s')
                ]);
                $canProcess = true;
            } else {
                $this->logger->warning('WACZ request is currently being processed by another worker', [
                    'wacz_request_id' => $waczRequestId,
                    'started_at' => $startedAt->format('Y-m-d H:i:s')
                ]);
            }
        }

        if (!$canProcess) {
            $this->logger->warning('WACZ request is not in a processable state, skipping', [
                'wacz_request_id' => $waczRequestId,
                'current_status' => $currentStatus
            ]);
            return;
        }

        // Set status to processing to prevent other workers from picking it up
        try {
            $waczRequest->setStatus(WaczRequest::STATUS_PROCESSING);
            $waczRequest->setStartedAt(new \DateTime());
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to update WACZ request status to processing', [
                'wacz_request_id' => $waczRequestId,
                'error' => $e->getMessage()
            ]);
            // Throw recoverable exception to retry later
            throw new RecoverableMessageHandlingException('Failed to update request status, will retry', 0, $e);
        }

        try {
            $success = $this->waczGeneratorService->processWaczRequest($waczRequest);

            if (!$success) {
                $this->logger->error('WACZ processing failed', [
                    'wacz_request_id' => $waczRequestId,
                    'error_message' => $waczRequest->getErrorMessage()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Critical error during WACZ processing', [
                'wacz_request_id' => $waczRequestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                $this->entityManager->refresh($waczRequest);
                $waczRequest->setStatus(WaczRequest::STATUS_FAILED);
                $waczRequest->setErrorMessage($e->getMessage());
                $waczRequest->setCompletedAt(new \DateTime());
                $this->entityManager->flush();
            } catch (\Exception $updateException) {
                $this->logger->error('Failed to update WACZ request status to failed', [
                    'wacz_request_id' => $waczRequestId,
                    'original_error' => $e->getMessage(),
                    'update_error' => $updateException->getMessage()
                ]);
            }

            // Don't throw the exception to prevent message retry for business logic errors
        }
    }
}