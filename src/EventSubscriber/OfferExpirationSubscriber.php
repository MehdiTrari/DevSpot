<?php

namespace App\EventSubscriber;

use App\Service\OfferLifecycleManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OfferExpirationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OfferLifecycleManager $offerLifecycleManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/_profiler') || str_starts_with($path, '/_wdt') || str_starts_with($path, '/assets')) {
            return;
        }

        $this->offerLifecycleManager->expireDueOffers();
    }
}
