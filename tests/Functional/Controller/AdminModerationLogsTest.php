<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\AdminActionLogRepository;
use App\Repository\UserRepository;
use App\Service\AdminModerationLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminModerationLogsTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testBanUserCreatesAdminActionLogWithReasonAndMetadata(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUserWithStatus(
            sprintf('admin_ban_%s@example.com', bin2hex(random_bytes(6))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createUserWithStatus(
            sprintf('target_ban_%s@example.com', bin2hex(random_bytes(6))),
            UserStatus::ACTIVE,
            ['ROLE_APPLICANT']
        );

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $banToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/ban"] input[name="_token"]', $target->getId()))
            ->attr('value');

        $client->request('POST', '/admin/users/'.$target->getId().'/ban', [
            '_token' => $banToken,
            'reason' => 'Comportement abusif confirme.',
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        $logsForTarget = $logRepository->findByTarget($target);

        self::assertNotEmpty($logsForTarget);
        $log = $logsForTarget[0];

        self::assertSame(AdminModerationLogger::BAN, $log->getAction());
        self::assertSame($admin->getId(), $log->getAdminUser()?->getId());
        self::assertSame($target->getId(), $log->getTargetUser()?->getId());
        self::assertSame('Comportement abusif confirme.', $log->getReason());
        self::assertSame('active', $log->getMetadata()['previousStatus'] ?? null);
        self::assertSame('banned', $log->getMetadata()['newStatus'] ?? null);
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertSame(UserStatus::BANNED, $userRepository->find($target->getId())?->getStatus());
        self::assertNotEmpty($logRepository->findByAdmin($admin));
    }

    public function testBanUserWithoutReasonDoesNotCreateAdminActionLog(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUserWithStatus(
            sprintf('admin_ban_missing_reason_%s@example.com', bin2hex(random_bytes(6))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createUserWithStatus(
            sprintf('target_ban_missing_reason_%s@example.com', bin2hex(random_bytes(6))),
            UserStatus::ACTIVE,
            ['ROLE_APPLICANT']
        );

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $banToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/ban"] input[name="_token"]', $target->getId()))
            ->attr('value');

        $client->request('POST', '/admin/users/'.$target->getId().'/ban', [
            '_token' => $banToken,
            'reason' => '',
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        self::assertSame([], array_values(array_filter(
            $logRepository->findByTarget($target),
            static fn ($log): bool => AdminModerationLogger::BAN === $log->getAction()
        )));

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertSame(UserStatus::ACTIVE, $userRepository->find($target->getId())?->getStatus());
    }

    private function initializeSchemaIfNeeded(): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->getConnection()->createSchemaManager()->tablesExist(['user', 'admin_action_log'])) {
            self::$schemaInitialized = true;

            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        self::$schemaInitialized = true;
    }

    /**
     * @param list<string> $roles
     */
    private function createUserWithStatus(string $email, UserStatus $status, array $roles): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus($status);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
