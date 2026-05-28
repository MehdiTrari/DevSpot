<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminSensitiveActionNotificationsTest extends WebTestCase
{
    public function testSuspendUserBroadcastsSensitiveNotificationToAdminsOnly(): void
    {
        $client = static::createClient();
        $this->resetSchema();

        $actingAdmin = $this->createUser('admin_actor_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN'], UserStatus::ACTIVE);
        $otherAdmin = $this->createUser('admin_other_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN'], UserStatus::ACTIVE);
        $target = $this->createUser('target_suspend_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_APPLICANT'], UserStatus::ACTIVE);
        $nonAdmin = $this->createUser('non_admin_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_RECRUITER'], UserStatus::ACTIVE);

        $client->loginUser($actingAdmin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/suspend"] input[name="_token"]', $target->getId()))
            ->attr('value');

        $client->request('POST', '/admin/users/'.$target->getId().'/suspend', [
            '_token' => (string) $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $actingAdmin = $entityManager->getRepository(User::class)->find($actingAdmin->getId());
        $otherAdmin = $entityManager->getRepository(User::class)->find($otherAdmin->getId());
        $nonAdmin = $entityManager->getRepository(User::class)->find($nonAdmin->getId());
        $target = $entityManager->getRepository(User::class)->find($target->getId());

        self::assertInstanceOf(User::class, $actingAdmin);
        self::assertInstanceOf(User::class, $otherAdmin);
        self::assertInstanceOf(User::class, $nonAdmin);
        self::assertInstanceOf(User::class, $target);

        self::assertSame(1, $this->countSensitiveNotifications($actingAdmin));
        self::assertSame(1, $this->countSensitiveNotifications($otherAdmin));
        self::assertSame(0, $this->countSensitiveNotifications($nonAdmin));
        self::assertSame(0, $this->countSensitiveNotifications($target));

        $notification = $this->findSensitiveNotification($otherAdmin);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertStringContainsString('suspension d\'un utilisateur', (string) $notification->getContent());
        self::assertStringContainsString((string) $target->getEmail(), (string) $notification->getContent());
        self::assertStringContainsString((string) $actingAdmin->getEmail(), (string) $notification->getContent());
    }

    public function testPendingValidationBroadcastsSensitiveNotificationToAllAdmins(): void
    {
        $client = static::createClient();
        $this->resetSchema();

        $actingAdmin = $this->createUser('admin_validate_actor_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN'], UserStatus::ACTIVE);
        $otherAdmin = $this->createUser('admin_validate_other_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN'], UserStatus::ACTIVE);
        $target = $this->createUser('target_pending_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_APPLICANT'], UserStatus::PENDING);

        $client->loginUser($actingAdmin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/validate"] input[name="_token"]', $target->getId()))
            ->attr('value');

        $client->request('POST', '/admin/users/'.$target->getId().'/validate', [
            '_token' => (string) $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $actingAdmin = $entityManager->getRepository(User::class)->find($actingAdmin->getId());
        $otherAdmin = $entityManager->getRepository(User::class)->find($otherAdmin->getId());
        $target = $entityManager->getRepository(User::class)->find($target->getId());

        self::assertInstanceOf(User::class, $actingAdmin);
        self::assertInstanceOf(User::class, $otherAdmin);
        self::assertInstanceOf(User::class, $target);
        self::assertSame(UserStatus::ACTIVE, $target->getStatus());

        self::assertSame(1, $this->countSensitiveNotifications($actingAdmin));
        self::assertSame(1, $this->countSensitiveNotifications($otherAdmin));

        $notification = $this->findSensitiveNotification($otherAdmin);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertStringContainsString('validation d\'un compte en attente', (string) $notification->getContent());
        self::assertStringContainsString((string) $target->getEmail(), (string) $notification->getContent());
        self::assertStringContainsString((string) $actingAdmin->getEmail(), (string) $notification->getContent());
    }

    private function countSensitiveNotifications(User $user): int
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(Notification::class)->count([
            'user' => $user,
            'type' => NotificationType::ADMIN_SENSITIVE_ACTION,
        ]);
    }

    private function findSensitiveNotification(User $user): ?Notification
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $notification = $entityManager->getRepository(Notification::class)->findOneBy([
            'user' => $user,
            'type' => NotificationType::ADMIN_SENSITIVE_ACTION,
        ]);

        return $notification instanceof Notification ? $notification : null;
    }

    private function createUser(string $email, array $roles, UserStatus $status): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus($status);
        $user->setIsVerified(UserStatus::ACTIVE === $status);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function resetSchema(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
