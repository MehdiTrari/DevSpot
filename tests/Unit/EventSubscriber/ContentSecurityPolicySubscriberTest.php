<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ContentSecurityPolicySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ContentSecurityPolicySubscriberTest extends TestCase
{
    public function testSubscribedEventsAreDeclared(): void
    {
        self::assertSame([
            KernelEvents::RESPONSE => 'onKernelResponse',
        ], ContentSecurityPolicySubscriber::getSubscribedEvents());
    }

    public function testItAddsPolicyHeaderOnMainHtmlResponses(): void
    {
        $subscriber = new ContentSecurityPolicySubscriber();
        $response = new Response('<html></html>');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        $subscriber->onKernelResponse($this->createResponseEvent($response));

        $policy = $response->headers->get('Content-Security-Policy');
        self::assertNotNull($policy);
        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://npmcdn.com", $policy);
    }

    public function testItSkipsNonHtmlResponsesAndExistingHeaders(): void
    {
        $subscriber = new ContentSecurityPolicySubscriber();

        $jsonResponse = new Response('{"ok":true}');
        $jsonResponse->headers->set('Content-Type', 'application/json');
        $subscriber->onKernelResponse($this->createResponseEvent($jsonResponse));
        self::assertFalse($jsonResponse->headers->has('Content-Security-Policy'));

        $htmlResponse = new Response('<html></html>');
        $htmlResponse->headers->set('Content-Type', 'text/html');
        $htmlResponse->headers->set('Content-Security-Policy', "default-src 'none'");
        $subscriber->onKernelResponse($this->createResponseEvent($htmlResponse));
        self::assertSame("default-src 'none'", $htmlResponse->headers->get('Content-Security-Policy'));
    }

    public function testItSkipsSubRequests(): void
    {
        $subscriber = new ContentSecurityPolicySubscriber();
        $response = new Response('<html></html>');

        $subscriber->onKernelResponse($this->createResponseEvent($response, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    private function createResponseEvent(Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            $requestType,
            $response,
        );
    }
}
