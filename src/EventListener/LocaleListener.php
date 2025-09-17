<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

class LocaleListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $locale = $session->get('_locale', 'en');
        $request->setLocale($locale);
    }
}
