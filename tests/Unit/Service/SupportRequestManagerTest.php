<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SupportRequest;
use App\Entity\User;
use App\Entity\Notification;
use App\Enum\NotificationType;
use App\Repository\FavoriteProfileRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManager;
use App\Service\SupportRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SupportRequestManagerTest extends TestCase
{
    public function testSubmitPersistsRequestNotifiesAdminsAndSendsOneEmailPerAdmin(): void
    {
        $requester = new User();
        $requester->setEmail('requester@example.com');
        $requester->setRoles(['ROLE_APPLICANT']);

        $supportRequest = (new SupportRequest())
            ->setUser($requester)
            ->setRequesterDisplayName('Requester Example')
            ->setRequesterEmail('requester@example.com')
            ->setSubject('Probleme de notifications')
            ->setMessage('Bonjour, je ne retrouve plus mes alertes depuis ce matin.');

        $firstAdmin = new User();
        $firstAdmin->setEmail('z-admin@example.com');
        $firstAdmin->setRoles(['ROLE_ADMIN']);

        $secondAdmin = new User();
        $secondAdmin->setEmail('a-admin@example.com');
        $secondAdmin->setRoles(['ROLE_ADMIN']);

        $persistedEntities = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $notificationRepository = $this->createMock(NotificationRepository::class);
        $notificationRepository->expects(self::exactly(2))
            ->method('existsForUserTypeAndLink')
            ->willReturn(false);

        $favoriteProfileRepository = $this->createStub(FavoriteProfileRepository::class);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::exactly(2))
            ->method('findAdmins')
            ->willReturn([$firstAdmin, $secondAdmin]);

        $sentEmails = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('send')
            ->willReturnCallback(static function ($message) use (&$sentEmails): void {
                self::assertInstanceOf(TemplatedEmail::class, $message);
                $sentEmails[] = $message;
            });

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static function (string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
                self::assertSame('app_admin_support_requests', $route);

                return UrlGeneratorInterface::ABSOLUTE_URL === $referenceType
                    ? 'https://devspot.test/admin/support'
                    : '/admin/support';
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $notificationManager = new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );

        $manager = new SupportRequestManager(
            $entityManager,
            $notificationManager,
            $userRepository,
            $mailer,
            $urlGenerator,
            $logger,
        );

        self::assertTrue($manager->submit($supportRequest));
        self::assertCount(3, $persistedEntities);
        self::assertSame($supportRequest, $persistedEntities[0]);
        self::assertContainsOnlyInstancesOf(Notification::class, array_slice($persistedEntities, 1));
        self::assertCount(2, $sentEmails);

        $recipients = array_map(
            static fn (TemplatedEmail $email): string => $email->getTo()[0]->getAddress(),
            $sentEmails,
        );

        self::assertSame([
            'a-admin@example.com',
            'z-admin@example.com',
        ], $recipients);

        foreach ($sentEmails as $email) {
            self::assertSame('no-reply@devspot.software', $email->getFrom()[0]->getAddress());
            self::assertSame('DevSpot Support Bot', $email->getFrom()[0]->getName());
            self::assertSame('Nouvelle demande de support : Probleme de notifications', $email->getSubject());
            self::assertSame('emails/support_request_admin.html.twig', $email->getHtmlTemplate());

            $context = $email->getContext();
            self::assertSame('Requester Example', $context['requesterDisplayName'] ?? null);
            self::assertSame('requester@example.com', $context['requesterEmail'] ?? null);
            self::assertSame('Probleme de notifications', $context['supportSubject'] ?? null);
            self::assertSame('Bonjour, je ne retrouve plus mes alertes depuis ce matin.', $context['supportMessage'] ?? null);
            self::assertSame('https://devspot.test/admin/support', $context['adminUrl'] ?? null);
            self::assertSame($supportRequest->getCreatedAt(), $context['submittedAt'] ?? null);
        }

        $notifications = array_slice($persistedEntities, 1);
        foreach ($notifications as $notification) {
            self::assertInstanceOf(Notification::class, $notification);
            self::assertSame(NotificationType::SUPPORT_REQUEST, $notification->getType());
            self::assertSame('Nouvelle demande de support', $notification->getTitle());
            self::assertStringContainsString('Probleme de notifications', (string) $notification->getContent());
        }
    }

    public function testSubmitReturnsFalseWhenAnAdminEmailFails(): void
    {
        $requester = new User();
        $requester->setEmail('requester@example.com');
        $requester->setRoles(['ROLE_APPLICANT']);

        $supportRequest = (new SupportRequest())
            ->setUser($requester)
            ->setRequesterDisplayName('Requester Example')
            ->setRequesterEmail('requester@example.com')
            ->setSubject('Sujet support')
            ->setMessage('Message de support suffisamment detaille.');

        $firstAdmin = new User();
        $firstAdmin->setEmail('a-admin@example.com');
        $firstAdmin->setRoles(['ROLE_ADMIN']);

        $secondAdmin = new User();
        $secondAdmin->setEmail('b-admin@example.com');
        $secondAdmin->setRoles(['ROLE_ADMIN']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $notificationRepository = $this->createMock(NotificationRepository::class);
        $notificationRepository->expects(self::exactly(2))
            ->method('existsForUserTypeAndLink')
            ->willReturn(false);

        $favoriteProfileRepository = $this->createStub(FavoriteProfileRepository::class);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::exactly(2))
            ->method('findAdmins')
            ->willReturn([$firstAdmin, $secondAdmin]);

        $sendCount = 0;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('send')
            ->willReturnCallback(function () use (&$sendCount): void {
                ++$sendCount;

                if (1 === $sendCount) {
                    throw new \RuntimeException('SMTP indisponible');
                }
            });

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static function (string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
                self::assertSame('app_admin_support_requests', $route);

                return UrlGeneratorInterface::ABSOLUTE_URL === $referenceType
                    ? 'https://devspot.test/admin/support'
                    : '/admin/support';
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Envoi email support admin echoue.',
                self::callback(static function (array $context): bool {
                    return array_key_exists('adminUserId', $context)
                        && array_key_exists('supportRequestId', $context)
                        && 'SMTP indisponible' === $context['error'];
                })
            );

        $notificationManager = new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );

        $manager = new SupportRequestManager(
            $entityManager,
            $notificationManager,
            $userRepository,
            $mailer,
            $urlGenerator,
            $logger,
        );

        self::assertFalse($manager->submit($supportRequest));
    }
}
