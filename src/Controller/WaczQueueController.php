<?php

namespace App\Controller;

use App\Service\MessengerQueueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class WaczQueueController extends AbstractController
{
    public function __construct(
        private readonly MessengerQueueService $queueService
    ) {}

    #[Route('/wacz/queue/status', name: 'wacz_queue_status', methods: ['GET'])]
    public function queueStatus(): JsonResponse
    {
        $statistics = $this->queueService->getQueueStatistics();
        $workersActive = $this->queueService->areWorkersActive();

        return new JsonResponse([
            'queue_statistics' => $statistics,
            'workers_active' => $workersActive,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }
}