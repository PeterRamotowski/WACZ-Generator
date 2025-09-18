<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/change/{_locale}', name: 'wacz_change_locale', requirements: ['_locale' => 'en|pl'])]
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