<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Service\ChatMercure;
use App\Twig\MessageExtension;
use App\Twig\NotificationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mercure\HubInterface;
use Twig\Environment;

final class MessageAndNotificationExtensionsTest extends TestCase
{
    public function testMessageExtensionExposesExpectedFunctions(): void
    {
        $extension = new MessageExtension(
            $this->createStub(MessageRepository::class),
            $this->createChatMercure(false),
        );

        $names = array_map(static fn ($function): string => $function->getName(), $extension->getFunctions());

        self::assertSame([
            'chat_unread_count',
            'chat_unread_mercure_topic',
            'chat_mercure_needs_credentials',
        ], $names);
    }

    public function testMessageExtensionReturnsSafeDefaultsWithoutUser(): void
    {
        $extension = new MessageExtension(
            $this->createStub(MessageRepository::class),
            $this->createChatMercure(true),
        );

        self::assertSame(0, $extension->chatUnreadCount(null));
        self::assertNull($extension->chatUnreadMercureTopic(null));
        self::assertFalse($extension->chatMercureNeedsCredentials());
    }

    public function testMessageExtensionDelegatesUnreadHelpersForAuthenticatedUser(): void
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository
            ->expects(self::once())
            ->method('countUnreadForUser')
            ->willReturn(7);

        $extension = new MessageExtension($messageRepository, $this->createChatMercure(false));
        $user = (new User())->setEmail('dev@example.com')->setRoles(['ROLE_APPLICANT']);

        self::assertSame(7, $extension->chatUnreadCount($user));
        self::assertSame('https://devspot/messages/users/0/unread-count', $extension->chatUnreadMercureTopic($user));
        self::assertTrue($extension->chatMercureNeedsCredentials());
    }

    public function testNotificationExtensionExposesFunctionAndReturnsSafeDefaults(): void
    {
        $repository = $this->createStub(NotificationRepository::class);
        $extension = new NotificationExtension($repository);

        $names = array_map(static fn ($function): string => $function->getName(), $extension->getFunctions());

        self::assertSame(['unread_notifications_count'], $names);
        self::assertSame(0, $extension->unreadNotificationsCount(null));
    }

    public function testNotificationExtensionDelegatesUnreadCountForUser(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository
            ->expects(self::once())
            ->method('countUnreadForUser')
            ->willReturn(4);

        $extension = new NotificationExtension($repository);
        $user = (new User())->setEmail('recruiter@example.com')->setRoles(['ROLE_RECRUITER']);

        self::assertSame(4, $extension->unreadNotificationsCount($user));
    }

    private function createChatMercure(bool $debug): ChatMercure
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('isDebug')->willReturn($debug);

        return new ChatMercure(
            $this->createStub(HubInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(MessageRepository::class),
            $kernel,
        );
    }
}