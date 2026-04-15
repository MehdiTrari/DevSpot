<?php

namespace App\Tests\Functional\Service;

use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\ActivityLogRepository;
use App\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LoggerServiceTest extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    public function testLogMatchingCalculatedStoresScoresInMetadata(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var LoggerService $loggerService */
        $loggerService = static::getContainer()->get(LoggerService::class);
        /** @var ActivityLogRepository $activityLogRepository */
        $activityLogRepository = static::getContainer()->get(ActivityLogRepository::class);

        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail(sprintf('matching_%s@example.com', bin2hex(random_bytes(8))));
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword('hashed-password');

        $profile = new DeveloperProfile();
        $profile->setFirstName('Alice');
        $profile->setLastName('Martin');
        $profile->setHeadline('Developpeuse backend');
        $profile->setSlug('alice-martin-' . bin2hex(random_bytes(3)));
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($user);
        $entityManager->persist($profile);
        $entityManager->flush();

        $scores = [
            'overall' => 0.91,
            'skills' => 0.87,
        ];

        $loggerService->logMatchingCalculated($user, $profile, $scores);

        $log = $activityLogRepository->findOneBy([
            'user' => $user,
            'action' => LoggerService::ACTION_MATCHING_CALCULATED,
        ], [
            'id' => 'DESC',
        ]);

        self::assertNotNull($log);
        self::assertSame(DeveloperProfile::class, $log->getEntityType());
        self::assertSame($profile->getId(), $log->getEntityId());
        self::assertSame($scores, $log->getMetadata()['matching_scores'] ?? null);
        self::assertNotNull($log->getCreatedAt());
    }

    private function ensureSchemaExists(EntityManagerInterface $entityManager): void
    {
        if (self::$schemaInitialized) {
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
}
