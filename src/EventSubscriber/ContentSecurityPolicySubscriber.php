<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $mercurePublicUrl = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        if (!$this->isHtmlResponse($response) || $response->headers->has('Content-Security-Policy')) {
            return;
        }

        $connectSources = ["'self'"];
        if (null !== $mercureConnectSource = $this->getMercureConnectSource()) {
            $connectSources[] = $mercureConnectSource;
        }

        $policy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://npmcdn.com",
            'connect-src '.implode(' ', $connectSources),
        ]);

        $response->headers->set('Content-Security-Policy', $policy);
    }

    private function getMercureConnectSource(): ?string
    {
        if ('' === trim($this->mercurePublicUrl)) {
            return null;
        }

        $parts = parse_url($this->mercurePublicUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $source = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $source .= ':'.$parts['port'];
        }

        return $source;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return '' === $contentType || str_contains($contentType, 'text/html');
    }
}
