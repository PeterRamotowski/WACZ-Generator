<?php

namespace App\Controller;

use App\DTO\WaczGenerationRequestDTO;
use App\Form\WaczGenerationRequestType;
use App\Service\MessengerQueueService;
use App\Service\Wacz\WaczGeneratorService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class WaczGeneratorController extends AbstractController
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly MessengerQueueService $queueService
    ) {}

    #[Route('/wacz/', name: 'wacz_index')]
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

    #[Route('/wacz/create', name: 'wacz_create', methods: ['GET', 'POST'])]
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

    #[Route('/wacz/list', name: 'wacz_list', methods: ['GET'])]
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

    #[Route('/wacz/{id}', name: 'wacz_show', methods: ['GET'], requirements: ['id' => '\d+'])]
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

    #[Route('/wacz/{id}/download', name: 'wacz_download', methods: ['GET'], requirements: ['id' => '\d+'])]
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
}
