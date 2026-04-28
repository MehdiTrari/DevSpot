<?php

namespace App\Tests\Unit\Service;

use App\Entity\ActivityLog;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LoggerServiceTest extends TestCase
{
    public function testLogPersistsActivityLogWithRequestIpAndMetadata(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/login', server: ['REMOTE_ADDR' => '203.0.113.10']));

        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn (ActivityLog $log): bool => LoggerService::PROFILE_UPDATE === $log->getAction()));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = new LoggerService($entityManager, $requestStack);
        $log = $service->log(
            LoggerService::PROFILE_UPDATE,
            $user,
            DeveloperProfile::class,
            12,
            ['step' => 2],
        );

        self::assertSame($user, $log->getUser());
        self::assertSame(DeveloperProfile::class, $log->getEntityType());
        self::assertSame(12, $log->getEntityId());
        self::assertSame('203.0.113.10', $log->getIpAddress());
        self::assertSame(['step' => 2], $log->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }

    public function testLogCanDeferFlush(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new LoggerService($entityManager, new RequestStack());
        $service->log(LoggerService::MATCHING_CALCULATED, flush: false);
    }
}
