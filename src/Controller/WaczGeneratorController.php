<?php

namespace App\Controller;

use App\DTO\WaczGenerationRequestDTO;
use App\Entity\WaczRequest;
use App\Form\WaczGenerationRequestType;
use App\Message\ProcessWaczMessage;
use App\Service\Wacz\WaczGeneratorService;
use App\Service\MessengerQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/wacz', name: 'wacz_')]
class WaczGeneratorController extends AbstractController
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $messageBus,
        private readonly MessengerQueueService $queueService
    ) {}

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $recentRequests = $this->waczGeneratorService->getRecentRequests(10);
        $statistics = $this->waczGeneratorService->getStatistics();
        $queueStatistics = $this->queueService->getQueueStatistics();
        $workersActive = $this->queueService->areWorkersActive();

        return $this->render('wacz/index.html.twig', [
            'recent_requests' => $recentRequests,
            'statistics' => $statistics,
            'queue_statistics' => $queueStatistics,
            'workers_active' => $workersActive,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $dto = new WaczGenerationRequestDTO();
        $form = $this->createForm(WaczGenerationRequestType::class, $dto);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $validationErrors = $this->waczGeneratorService->validateWaczRequest($dto);

            if (empty($validationErrors)) {
                try {
                    $waczRequest = $this->waczGeneratorService->createWaczRequest($dto);
                    
                    $this->addFlash('success', $this->translator->trans('messages.wacz_request_created_successfully'));
                    
                    return $this->redirectToRoute('wacz_show', [
                        'id' => $waczRequest->getId()
                    ]);
                    
                } catch (\Exception $e) {
                    $this->addFlash('error', $this->translator->trans('messages.error_creating_request', ['%error%' => $e->getMessage()]));
                }
            } else {
                foreach ($validationErrors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

        return $this->render('wacz/create.html.twig', [
            'form' => $form,
            'dto' => $dto,
        ]);
    }

    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $status = $request->query->get('status', 'all');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        
        switch ($status) {
            case 'pending':
                $requests = $this->waczGeneratorService->getPendingRequests();
                break;
            case 'completed':
                $requests = $this->waczGeneratorService->getCompletedRequests($limit);
                break;
            case 'failed':
                $requests = $this->waczGeneratorService->getFailedRequests($limit);
                break;
            default:
                $requests = $this->waczGeneratorService->getRecentRequests($limit);
                break;
        }

        return $this->render('wacz/list.html.twig', [
            'requests' => $requests,
            'current_status' => $status,
            'statistics' => $this->waczGeneratorService->getStatistics(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);
        
        if (!$waczRequest) {
            throw $this->createNotFoundException($this->translator->trans('messages.wacz_request_not_found'));
        }

        $progress = $this->waczGeneratorService->getWaczRequestProgress($waczRequest);
        $crawledPages = $this->waczGeneratorService->getCrawledPages($waczRequest);

        return $this->render('wacz/show.html.twig', [
            'wacz_request' => $waczRequest,
            'progress' => $progress,
            'crawled_pages' => $crawledPages,
        ]);
    }

    #[Route('/{id}/process', name: 'process', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    private function dispatchWaczProcessingMessage(WaczRequest $waczRequest): void
    {
        $message = new ProcessWaczMessage($waczRequest->getId());
        $this->messageBus->dispatch($message);
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): Response
    {
        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);

        if (!$waczRequest) {
            throw $this->createNotFoundException($this->translator->trans('messages.wacz_request_not_found'));
        }

        if (!$waczRequest->isCompleted()) {
            $this->addFlash('error', $this->translator->trans('messages.archive_not_ready_download'));
            return $this->redirectToRoute('wacz_show', ['id' => $id]);
        }

        $file = $this->waczGeneratorService->getWaczFileResponse($waczRequest);

        if (!$file) {
            $this->addFlash('error', $this->translator->trans('messages.archive_file_not_found'));
            return $this->redirectToRoute('wacz_show', ['id' => $id]);
        }

        $response = new BinaryFileResponse($file->getRealPath());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($file->getRealPath())
        );

        return $response;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $waczRequest = $this->waczGeneratorService->getWaczRequestById($id);

        if (!$waczRequest) {
            throw $this->createNotFoundException($this->translator->trans('messages.wacz_request_not_found'));
        }

        try {
            $success = $this->waczGeneratorService->deleteWaczRequest($waczRequest);

            if ($success) {
                $this->addFlash('success', $this->translator->trans('messages.wacz_request_deleted_successfully'));
            } else {
                $this->addFlash('error', $this->translator->trans('messages.failed_delete_request'));
            }

        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('messages.error_during_deletion', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('wacz_list');
    }

    #[Route('/delete-all', name: 'delete_all', methods: ['POST'])]
    public function deleteAll(Request $request): Response
    {
        try {
            $deleted = $this->waczGeneratorService->deleteAllRequests();
            $this->addFlash('success', $this->translator->trans('messages.deleted_requests_count', ['%count%' => $deleted]));

        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('messages.error_during_deletion', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('wacz_list');
    }

    #[Route('/{id}/progress', name: 'progress', methods: ['GET'], requirements: ['id' => '\d+'])]
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

    #[Route('/queue/status', name: 'queue_status', methods: ['GET'])]
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

    #[Route('/cleanup', name: 'cleanup', methods: ['POST'])]
    public function cleanup(Request $request): Response
    {
        $days = (int) $request->request->get('days', 30);
        $before = new \DateTime("-{$days} days");

        try {
            $deleted = $this->waczGeneratorService->cleanupOldRequests($before);
            $this->addFlash('success', $this->translator->trans('messages.deleted_old_requests_count', ['%count%' => $deleted]));

        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('messages.error_during_cleanup', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('wacz_list');
    }

    #[Route('/change-locale/{_locale}', name: 'change_locale', requirements: ['_locale' => 'en|pl'])]
    public function changeLocale(string $_locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $_locale);

        $referer = $request->headers->get('referer');
        if ($referer && $this->isSameDomain($referer, $request)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('wacz_index');
    }

    private function isSameDomain(string $url, Request $request): bool
    {
        $refererHost = parse_url($url, PHP_URL_HOST);
        $currentHost = $request->getHost();

        return $refererHost === $currentHost;
    }
}
