<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AccessDeniedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessDeniedHandlerTest extends TestCase
{
    public function testItRedirectsToDedicated403Route(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('app_error_403')
            ->willReturn('/403');

        $handler = new AccessDeniedHandler($urlGenerator);
        $response = $handler->handle(Request::create('/admin'), new AccessDeniedException());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/403', $response->getTargetUrl());
    }
}
