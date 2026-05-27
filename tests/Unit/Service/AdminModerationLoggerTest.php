<?php

namespace App\Tests\Unit\Service;

use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Service\AdminModerationLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AdminModerationLoggerTest extends TestCase
{
    public function testBanRequiresReason(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $service = new AdminModerationLogger($entityManager, $security);

        $this->expectException(\InvalidArgumentException::class);
        $service->log(AdminModerationLogger::BAN, new User(), new User(), '');
    }

    public function testOnlyAdminCanWriteModerationLog(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $service = new AdminModerationLogger($entityManager, $security);

        $this->expectException(\LogicException::class);
        $service->log(AdminModerationLogger::SUSPEND, new User(), new User(), 'Suspension test.');
    }

    public function testLogPersistsModerationDecision(): void
    {
        $admin = new User();
        $target = new User();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn (AdminActionLog $log): bool => AdminModerationLogger::BAN === $log->getAction()));
        $entityManager->expects(self::never())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $service = new AdminModerationLogger($entityManager, $security);
        $log = $service->log(
            AdminModerationLogger::BAN,
            $admin,
            $target,
            'Spam recruteur.',
            ['previousStatus' => 'active', 'newStatus' => 'banned'],
        );

        self::assertSame($admin, $log->getAdminUser());
        self::assertSame($target, $log->getTargetUser());
        self::assertSame('Spam recruteur.', $log->getReason());
        self::assertSame(['previousStatus' => 'active', 'newStatus' => 'banned'], $log->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }
}
