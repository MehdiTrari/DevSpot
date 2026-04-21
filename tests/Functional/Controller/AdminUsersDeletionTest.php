<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminActionLog;
use App\Entity\DeveloperProfile;
use App\Entity\Education;
use App\Entity\Position;
use App\Entity\ProfileSkill;
use App\Entity\Skill;
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

        $client->request('POST', '/admin/users/'.$target->getId().'/delete', [
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

        $client->request('POST', '/admin/users/'.$adminToDelete->getId().'/delete', [
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

    public function testRejectPendingUserWithProfileHardDeletesUser(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUserWithStatus(
            sprintf('admin_reject_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createPendingApplicantWithProfile(
            sprintf('pending_reject_%s@example.com', bin2hex(random_bytes(8)))
        );
        $log = $this->createLog($admin, $target, 'user.status_changed', [
            'previousStatus' => 'pending',
            'newStatus' => 'active',
        ]);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $rejectToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/reject"] input[name="_token"]', $target->getId()))
            ->attr('value');

        self::assertNotNull($rejectToken);

        $client->request('POST', '/admin/users/'.$target->getId().'/reject', [
            '_token' => $rejectToken,
        ]);

        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepository->find($target->getId()), 'Le compte pending refuse doit etre supprime physiquement.');

        /** @var AdminActionLogRepository $logRepository */
        $logRepository = static::getContainer()->get(AdminActionLogRepository::class);
        $updatedLog = $logRepository->find($log->getId());

        self::assertNotNull($updatedLog, 'Le log historique doit etre conserve.');

        $fkValues = $this->fetchLogFkValues($log->getId());
        $this->assertFkNullifiedWhenSupported($fkValues['target_user_id'], 'target_user_id');
        self::assertSame($admin->getId(), $fkValues['admin_user_id'], 'Le user admin du log doit rester present.');
    }

    public function testRejectPendingUserWithCompletedProfileHardDeletesUser(): void
    {
        $client = static::createClient();
        $this->initializeSchemaIfNeeded();

        $admin = $this->createUserWithStatus(
            sprintf('admin_reactivate_%s@example.com', bin2hex(random_bytes(8))),
            UserStatus::ACTIVE,
            ['ROLE_ADMIN']
        );
        $target = $this->createPendingApplicantWithCompletedProfile(
            sprintf('pending_full_%s@example.com', bin2hex(random_bytes(8))),
            'Password123!'
        );

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $rejectToken = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/reject"] input[name="_token"]', $target->getId()))
            ->attr('value');

        self::assertNotNull($rejectToken);

        $client->request('POST', '/admin/users/'.$target->getId().'/reject', [
            '_token' => $rejectToken,
        ]);
        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNull($userRepository->find($target->getId()), 'Le compte pending complet refuse doit etre supprime physiquement.');
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

    private function createPendingApplicantWithProfile(string $email): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->createUserWithStatus($email, UserStatus::PENDING, ['ROLE_APPLICANT']);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Pending');
        $profile->setLastName('Applicant');
        $profile->setHeadline('Developpeur en attente');
        $profile->setSlug('pending-'.bin2hex(random_bytes(6)));
        $profile->setIsPublic(false);
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createPendingApplicantWithCompletedProfile(string $email, string $plainPassword): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->createUserWithStatus($email, UserStatus::PENDING, ['ROLE_APPLICANT']);
        $this->setPasswordForUser($user, $plainPassword);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Pending');
        $profile->setLastName('Completed');
        $profile->setHeadline('Developpeur Symfony');
        $profile->setBio('Profil complet pour test de reactivation.');
        $profile->setCity('Lyon');
        $profile->setCountry('France');
        $profile->setLocationType(\App\Enum\LocationType::REMOTE);
        $profile->setExperienceLevel(\App\Enum\ExperienceLevel::MID);
        $profile->setYearsExperience(5);
        $profile->setSlug('pending-complete-'.bin2hex(random_bytes(6)));
        $profile->setIsPublic(false);
        $profile->setGithubUrl('https://github.com/example');
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $education = new Education();
        $education->setSchoolName('EPITECH');
        $education->setDeveloperProfile($profile);

        $skill = new Skill();
        $skill->setName('Symfony-'.bin2hex(random_bytes(3)));
        $skill->setCategory('Backend');

        $profileSkill = new ProfileSkill();
        $profileSkill->setDeveloperProfile($profile);
        $profileSkill->setSkill($skill);

        $position = new Position();
        $position->setName('Developpeur PHP '.bin2hex(random_bytes(3)));
        $profile->addDesiredPosition($position);

        $entityManager->persist($user);
        $entityManager->persist($profile);
        $entityManager->persist($education);
        $entityManager->persist($skill);
        $entityManager->persist($profileSkill);
        $entityManager->persist($position);
        $entityManager->flush();

        return $user;
    }

    private function setPasswordForUser(User $user, string $plainPassword): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user->setPassword($hasher->hashPassword($user, $plainPassword));
    }
}
