<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeveloperProfile;
use App\Entity\ProfileSkill;
use App\Entity\Skill;
use App\Repository\DeveloperProfileRepository;
use App\Service\AiMatchingClientInterface;
use App\Service\CandidateProfileEmbeddingService;
use App\Service\CandidateTextPreprocessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CandidateProfileEmbeddingServiceTest extends TestCase
{
    public function testRefreshEmbeddingsStoresFreshVectorsAndSkipsUnchangedProfiles(): void
    {
        $profile = (new DeveloperProfile())
            ->setFirstName('Alice')
            ->setLastName('Martin')
            ->setHeadline('Developpeuse React')
            ->setBio('reactjs nodejs api rest')
            ->setSlug('alice-martin')
            ->setYearsExperience(3);
        $this->setEntityId($profile, 101);
        $profile->addProfileSkill((new ProfileSkill())->setSkill((new Skill())->setName('Communication')->setCategory('Soft Skills')));

        $client = $this->createMock(AiMatchingClientInterface::class);
        $client
            ->expects(self::once())
            ->method('embedBatch')
            ->willReturn([
                ['embedding' => [0.1, 0.2], 'dimension' => 2, 'normalizedText' => 'candidate'],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $developerProfileRepository = $this->createMock(DeveloperProfileRepository::class);
        $developerProfileRepository
            ->expects(self::once())
            ->method('findStoredMatchingEmbeddingsByProfileIds')
            ->with([101])
            ->willReturn([
                101 => [
                    'embedding' => [0.1, 0.2],
                    'dimension' => 2,
                    'textHash' => hash('sha256', (new CandidateTextPreprocessor())->buildCandidateText($profile)),
                ],
            ]);

        $service = new CandidateProfileEmbeddingService($client, new CandidateTextPreprocessor(), $developerProfileRepository, $entityManager);

        $firstRun = $service->refreshEmbeddings([$profile], false, true);
        $secondRun = $service->refreshEmbeddings([$profile], false, true);

        self::assertSame([
            'processed' => 1,
            'refreshed' => 1,
            'skipped' => 0,
            'failed' => 0,
        ], $firstRun);
        self::assertSame([
            'processed' => 1,
            'refreshed' => 0,
            'skipped' => 1,
            'failed' => 0,
        ], $secondRun);
        self::assertSame([0.1, 0.2], $profile->getMatchingEmbedding());
        self::assertSame(2, $profile->getMatchingEmbeddingDimension());
        self::assertNotNull($profile->getMatchingEmbeddingTextHash());
        self::assertNotNull($profile->getMatchingEmbeddingUpdatedAt());
        self::assertSame([
            '101' => [
                'embedding' => [0.1, 0.2],
                'dimension' => 2,
            ],
        ], $service->storedEmbeddingsForProfiles([$profile]));
    }

    public function testStoredEmbeddingsForProfilesIgnoresStaleEmbeddings(): void
    {
        $profile = (new DeveloperProfile())
            ->setFirstName('Bob')
            ->setLastName('Martin')
            ->setHeadline('Developpeur Vue')
            ->setBio('vue')
            ->setSlug('bob-martin')
            ->setYearsExperience(2);
        $this->setEntityId($profile, 202);
        $profile
            ->setMatchingEmbedding([1.0, 0.0])
            ->setMatchingEmbeddingDimension(2)
            ->setMatchingEmbeddingTextHash('stale-hash')
            ->setMatchingEmbeddingUpdatedAt(new \DateTimeImmutable());

        $client = $this->createMock(AiMatchingClientInterface::class);
        $client->expects(self::never())->method('embedBatch');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $developerProfileRepository = $this->createMock(DeveloperProfileRepository::class);
        $developerProfileRepository
            ->expects(self::once())
            ->method('findStoredMatchingEmbeddingsByProfileIds')
            ->with([202])
            ->willReturn([
                202 => [
                    'embedding' => [1.0, 0.0],
                    'dimension' => 2,
                    'textHash' => 'stale-hash',
                ],
            ]);

        $service = new CandidateProfileEmbeddingService($client, new CandidateTextPreprocessor(), $developerProfileRepository, $entityManager);

        self::assertSame([], $service->storedEmbeddingsForProfiles([$profile]));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
