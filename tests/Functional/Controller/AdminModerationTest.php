<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\AdminActionLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminModerationTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testBanRequiresReason(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUser('admin_ban_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target_ban_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_APPLICANT']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/ban"] input[name="_token"]', $target->getId()))
            ->attr('value');

        self::assertNotNull($token);

        $client->request('POST', '/admin/users/'.$target->getId().'/ban', [
            '_token' => (string) $token,
            'reason' => '',
        ]);

        self::assertResponseRedirects('/admin/users');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'motif du bannissement est obligatoire');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $reloadedTarget = $userRepository->find($target->getId());

        self::assertInstanceOf(User::class, $reloadedTarget);
        self::assertSame(UserStatus::ACTIVE, $reloadedTarget->getStatus());

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        self::assertNull($logRepository->findOneBy(['targetUser' => $target, 'action' => 'BAN']));
    }

    public function testBanCreatesImmutableModerationLogWithReason(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUser('admin_log_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target_log_'.bin2hex(random_bytes(6)).'@example.com', ['ROLE_RECRUITER']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/ban"] input[name="_token"]', $target->getId()))
            ->attr('value');

        self::assertNotNull($token);

        $before = new \DateTimeImmutable();

        $client->request('POST', '/admin/users/'.$target->getId().'/ban', [
            '_token' => (string) $token,
            'reason' => 'Usurpation d identite constatee.',
            'durationDays' => '30',
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $reloadedTarget = $userRepository->find($target->getId());

        self::assertInstanceOf(User::class, $reloadedTarget);
        self::assertSame(UserStatus::BANNED, $reloadedTarget->getStatus());

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        $log = $logRepository->findOneBy([
            'adminUser' => $admin,
            'targetUser' => $target,
            'action' => 'BAN',
        ], [
            'id' => 'DESC',
        ]);

        self::assertInstanceOf(AdminActionLog::class, $log);
        self::assertSame('Usurpation d identite constatee.', $log->getReason());
        self::assertSame(30, $log->getMetadata()['durationDays'] ?? null);
        self::assertSame('active', $log->getMetadata()['previousStatus'] ?? null);
        self::assertSame('banned', $log->getMetadata()['newStatus'] ?? null);
        self::assertNotNull($log->getCreatedAt());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $log->getCreatedAt()->getTimestamp());
    }

    public function testModerationHistoryCanBeFilteredByAdminOrTarget(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $adminA = $this->createUser('history_admin_a_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $adminB = $this->createUser('history_admin_b_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $targetA = $this->createUser('history_target_a_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_APPLICANT']);
        $targetB = $this->createUser('history_target_b_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_RECRUITER']);

        $this->createLog($adminA, $targetA, 'BAN', 'Raison admin A');
        $this->createLog($adminB, $targetB, 'ADD_WHITELIST', 'Raison admin B');

        $client->loginUser($adminA);
        $client->request('GET', '/admin/moderation-history?adminEmail='.rawurlencode((string) $adminA->getEmail()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Raison admin A');
        self::assertSelectorTextNotContains('body', 'Raison admin B');

        $client->request('GET', '/admin/moderation-history?targetEmail='.rawurlencode((string) $targetB->getEmail()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Raison admin B');
        self::assertSelectorTextNotContains('body', 'Raison admin A');
    }

    private function createLog(User $adminUser, User $targetUser, string $action, string $reason): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $log = new AdminActionLog();
        $log->setAction($action);
        $log->setReason($reason);
        $log->setAdminUser($adminUser);
        $log->setTargetUser($targetUser);
        $log->setMetadata([
            'previousStatus' => 'pending',
            'newStatus' => 'active',
        ]);

        $entityManager->persist($log);
        $entityManager->flush();
    }

    private function createUser(string $email, array $roles): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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
