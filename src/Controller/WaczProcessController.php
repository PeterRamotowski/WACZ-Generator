<?php

namespace App\Controller;

use App\Entity\WaczRequest;
use App\Message\ProcessWaczMessage;
use App\Service\Wacz\WaczGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class WaczProcessController extends AbstractController
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $messageBus
    ) {}

    #[Route('/wacz/{id}/process', name: 'wacz_process', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function process(Request $request, int $id): Response
    {
        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);

        if (!$waczRequest) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $this->translator->trans('messages.wacz_request_not_found')], 404);
            }
            throw $this->createNotFoundException($this->translator->trans('messages.wacz_request_not_found'));
        }

        if ($waczRequest->getStatus() !== 'pending') {
            $message = $this->translator->trans('messages.request_already_processed');
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $message], 400);
            }
            $this->addFlash('error', $message);
            return $this->redirectToRoute('wacz_show', ['id' => $id]);
        }

        try {
            $this->dispatchWaczProcessingMessage($waczRequest);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $this->translator->trans('messages.processing_started_background')
                ]);
            }
            
            $this->addFlash('success', $this->translator->trans('messages.processing_started_background'));

        } catch (\Exception $e) {
            $waczRequest->setStatus(WaczRequest::STATUS_FAILED);
            $waczRequest->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Failed to dispatch WACZ processing message', [
                'request_id' => $waczRequest->getId(),
                'error' => $e->getMessage()
            ]);
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $this->translator->trans('messages.error_starting_processing', ['%error%' => $e->getMessage()])], 500);
            }
            $this->addFlash('error', $this->translator->trans('messages.error_starting_processing', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('wacz_show', ['id' => $id]);
    }

    #[Route('/wacz/{id}/progress', name: 'wacz_progress', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function progress(int $id): JsonResponse
    {
        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);

        if (!$waczRequest) {
            return new JsonResponse(['error' => $this->translator->trans('messages.wacz_request_not_found')], 404);
        }

        $progress = $this->waczGeneratorService->getWaczRequestProgress($waczRequest);
        $crawledPages = $this->waczGeneratorService->getCrawledPages($waczRequest);

        $response = [
            'status' => $progress['status'],
            'total_pages' => $progress['total_pages'],
            'successful_pages' => $progress['successful_pages'],
            'error_pages' => $progress['error_pages'],
            'skipped_pages' => $progress['skipped_pages'],
            'progress_percentage' => $progress['progress_percentage'],
            'started_at' => $progress['started_at'],
            'estimated_completion' => $progress['estimated_completion'],
            'crawled_pages_count' => count($crawledPages)
        ];

        return new JsonResponse($response);
    }

    private function dispatchWaczProcessingMessage(WaczRequest $waczRequest): void
    {
        $message = new ProcessWaczMessage($waczRequest->getId());
        $this->messageBus->dispatch($message);
    }
}