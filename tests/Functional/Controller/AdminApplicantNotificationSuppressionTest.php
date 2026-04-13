<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminApplicantNotificationSuppressionTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testAdminRoleChangeDoesNotNotifyApplicantTarget(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUser('admin_role_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN']);
        $applicant = $this->createUser('applicant_role_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_APPLICANT']);

        $initialCount = $this->countNotificationsForUser($applicant);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/role"] input[name="_token"]', $applicant->getId()))
            ->attr('value');

        self::assertNotNull($token);

        $client->request('POST', '/admin/users/'.$applicant->getId().'/role', [
            '_token' => (string) $token,
            'role' => 'ROLE_RECRUITER',
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedApplicant = $entityManager->getRepository(User::class)->find($applicant->getId());
        self::assertInstanceOf(User::class, $reloadedApplicant);
        self::assertContains('ROLE_RECRUITER', $reloadedApplicant->getRoles());
        self::assertSame($initialCount, $this->countNotificationsForUser($reloadedApplicant));
    }

    public function testAdminStatusChangeDoesNotNotifyApplicantTarget(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUser('admin_status_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN']);
        $applicant = $this->createUser('applicant_status_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_APPLICANT']);

        $initialCount = $this->countNotificationsForUser($applicant);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/suspend"] input[name="_token"]', $applicant->getId()))
            ->attr('value');

        self::assertNotNull($token);

        $client->request('POST', '/admin/users/'.$applicant->getId().'/suspend', [
            '_token' => (string) $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedApplicant = $entityManager->getRepository(User::class)->find($applicant->getId());
        self::assertInstanceOf(User::class, $reloadedApplicant);
        self::assertSame(UserStatus::SUSPENDED, $reloadedApplicant->getStatus());
        self::assertSame($initialCount, $this->countNotificationsForUser($reloadedApplicant));
    }

    private function countNotificationsForUser(User $user): int
    {
        /** @var NotificationRepository $notificationRepository */
        $notificationRepository = static::getContainer()->get(NotificationRepository::class);

        return $notificationRepository->count(['user' => $user]);
    }

    private function createUser(string $email, array $roles): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->initializeSchemaIfNeeded();

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function initializeSchemaIfNeeded(): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        self::$schemaInitialized = true;
    }
}
