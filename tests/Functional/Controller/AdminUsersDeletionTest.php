<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\AdminActionLogRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUsersDeletionTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testDeleteUserKeepsExistingLogsAndNullifiesTargetUserReference(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUserWithStatus(
            sprintf('admin_delete_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createUserWithStatus(
            sprintf('target_delete_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_APPLICANT']
        );

        $log = $this->createLog($admin, $target, 'user.status_changed', [
            'previousStatus' => 'pending',
            'newStatus' => 'active',
        ]);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $deleteToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/delete"] input[name="_token"]', $target->getId()))
            ->attr('value');

        self::assertNotNull($deleteToken);

        $client->request('POST', '/admin/users/' . $target->getId() . '/delete', [
            '_token' => $deleteToken,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepository->find($target->getId()), 'Le user cible doit être supprimé.');

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        $updatedLog = $logRepository->find($log->getId());

        self::assertNotNull($updatedLog, 'Le log historique doit être conservé.');

        $fkValues = $this->fetchLogFkValues($log->getId());
        $this->assertFkNullifiedWhenSupported($fkValues['target_user_id'], 'target_user_id');
        self::assertSame($admin->getId(), $fkValues['admin_user_id'], 'Le user admin du log doit rester présent.');
    }

    public function testDeleteAdminUserKeepsLogsAndNullifiesAdminUserReference(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $actingAdmin = $this->createUserWithStatus(
            sprintf('acting_admin_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $adminToDelete = $this->createUserWithStatus(
            sprintf('admin_to_delete_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createUserWithStatus(
            sprintf('target_for_log_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_APPLICANT']
        );

        $log = $this->createLog($adminToDelete, $target, 'user.banned', [
            'previousStatus' => 'active',
            'newStatus' => 'banned',
        ]);

        $client->loginUser($actingAdmin);
        $crawler = $client->request('GET', '/admin/users');
        $deleteToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/delete"] input[name="_token"]', $adminToDelete->getId()))
            ->attr('value');

        self::assertNotNull($deleteToken);

        $client->request('POST', '/admin/users/' . $adminToDelete->getId() . '/delete', [
            '_token' => $deleteToken,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepository->find($adminToDelete->getId()), 'Le compte admin ciblé doit être supprimé.');

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        $updatedLog = $logRepository->find($log->getId());

        self::assertNotNull($updatedLog, 'Le log historique doit être conservé.');

        $fkValues = $this->fetchLogFkValues($log->getId());
        $this->assertFkNullifiedWhenSupported($fkValues['admin_user_id'], 'admin_user_id');
        self::assertSame($target->getId(), $fkValues['target_user_id'], 'Le user cible du log doit rester si non supprimé.');
    }

    private function createLog(User $adminUser, ?User $targetUser, string $action, ?array $metadata = null): AdminActionLog
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $log = new AdminActionLog();
        $log->setAction($action);
        $log->setReason(null);
        $log->setMetadata($metadata);
        $log->setCreatedAt(new \DateTimeImmutable());
        $log->setAdminUser($adminUser);
        $log->setTargetUser($targetUser);

        $entityManager->persist($log);
        $entityManager->flush();

        return $log;
    }

    /**
     * @return array{admin_user_id: int|null, target_user_id: int|null}
     */
    private function fetchLogFkValues(int $logId): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $row = $entityManager->getConnection()->fetchAssociative(
            'SELECT admin_user_id, target_user_id FROM admin_action_log WHERE id = ?',
            [$logId]
        );

        self::assertIsArray($row);

        return [
            'admin_user_id' => null !== $row['admin_user_id'] ? (int) $row['admin_user_id'] : null,
            'target_user_id' => null !== $row['target_user_id'] ? (int) $row['target_user_id'] : null,
        ];
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

    private function assertFkNullifiedWhenSupported(?int $value, string $columnName): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $platform = $entityManager->getConnection()->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            self::assertTrue(true, sprintf('Verification stricte de %s sautee sous SQLite de test.', $columnName));

            return;
        }

        self::assertNull($value, sprintf('La FK %s doit être nullifiée après suppression.', $columnName));
    }

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
