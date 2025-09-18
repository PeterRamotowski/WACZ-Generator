<?php

namespace App\Controller;

use App\Service\Wacz\WaczGeneratorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class WaczAdminController extends AbstractController
{
    public function __construct(
        private readonly WaczGeneratorService $waczGeneratorService,
        private readonly TranslatorInterface $translator
    ) {}

    #[Route('/wacz/{id}/delete', name: 'wacz_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    #[Route('/wacz/delete-all', name: 'wacz_delete_all', methods: ['POST'])]
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

    #[Route('/wacz/cleanup', name: 'wacz_cleanup', methods: ['POST'])]
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
}