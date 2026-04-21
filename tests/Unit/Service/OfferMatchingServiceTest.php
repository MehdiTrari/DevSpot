<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeveloperProfile;
use App\Entity\Experience;
use App\Entity\JobOffer;
use App\Entity\Position;
use App\Entity\ProfileSkill;
use App\Entity\Skill;
use App\Entity\Technology;
use App\Enum\ContractType;
use App\Enum\LocationType;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\FairnessAuditor;
use App\Matching\Service\SkillMatcher;
use App\Repository\PositionRepository;
use App\Repository\SkillRepository;
use App\Repository\TechnologyRepository;
use App\Service\AiMatchingClientInterface;
use App\Service\CandidateSkillInferenceService;
use App\Service\EnrichedMatchingService;
use App\Service\OfferMatchingService;
use App\Service\SemanticMatchingService;
use PHPUnit\Framework\TestCase;

final class OfferMatchingServiceTest extends TestCase
{
    public function testBuildSingleOfferMatchExtractsSkillsBuildsPayloadAndSortsMatches(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            new SemanticMatchingService($client),
            new EnrichedMatchingService(new CandidateSkillInferenceService($client), new SemanticMatchingService($client)),
            $skillRepository,
            $technologyRepository,
            $positionRepository,
        );

        $offer = (new JobOffer())
            ->setTitle('Développeur Symfony React')
            ->setDescription('Communication et API development à Paris')
            ->setLocation('Paris')
            ->setLocationType(LocationType::REMOTE)
            ->setContractType(ContractType::FULL_TIME)
            ->setExperienceLevel(3);
        $this->setEntityId($offer, 51);

        [$alice, $bob] = $this->createDevelopers();

        $skillRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([
                (new Skill())->setName('Communication')->setCategory('Soft Skills'),
                (new Skill())->setName('PHP')->setCategory('Backend'),
            ]);
        $technologyRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([
                (new Technology())->setName('Symfony')->setCategory('Backend'),
                (new Technology())->setName('React')->setCategory('Frontend'),
            ]);
        $positionRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([
                (new Position())->setName('API development'),
            ]);

        $client
            ->expects(self::once())
            ->method('embedBatch')
            ->willReturn([
                ['embedding' => [1.0, 0.0], 'dimension' => 256, 'normalizedText' => 'offer'],
                ['embedding' => [0.875, 0.4841229183], 'dimension' => 256, 'normalizedText' => 'alice'],
                ['embedding' => [1.0, 0.0], 'dimension' => 256, 'normalizedText' => 'bob'],
            ]);
        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->willReturn([
                [
                    'inferredSoftSkills' => ['Communication'],
                    'inferredTransferableSkills' => ['Leadership'],
                    'inferredTechnicalSkills' => [],
                    'confidence' => ['Communication' => 1.0],
                    'normalizedText' => 'alice enriched',
                ],
                [
                    'inferredSoftSkills' => [],
                    'inferredTransferableSkills' => [],
                    'inferredTechnicalSkills' => [
                        ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 1.0],
                    ],
                    'confidence' => [],
                    'normalizedText' => 'bob enriched',
                ],
            ]);

        $payload = $service->buildSingleOfferMatch($offer, [$alice, $bob]);

        self::assertSame('Développeur Symfony React', $payload['offer']['title']);
        self::assertSame(['Symfony', 'React', 'API development'], $payload['extractedRequirements']['hardSkills']);
        self::assertSame(['Communication'], $payload['extractedRequirements']['softSkills']);
        self::assertCount(2, $payload['matches']);
        self::assertSame('Bob Martin', $payload['matches'][0]['fullName']);
        self::assertSame(100.0, $payload['matches'][0]['semanticEnrichedPercentage']);
        self::assertSame(100.0, $payload['matches'][0]['semanticPercentage']);
        self::assertSame('Alice Dupont', $payload['matches'][1]['fullName']);
        self::assertSame(['communication'], $payload['matches'][1]['matchedSoftSkills']);
        self::assertSame(['Leadership'], $payload['matches'][1]['inferredTransferableSkills']);
        self::assertStringContainsString('[EMAIL]', $payload['matches'][1]['anonymizedCv']);
        self::assertTrue($payload['semantic']['available']);
        self::assertTrue($payload['enriched']['available']);
    }

    public function testBuildOfferMatchesMapsEachOffer(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            new SemanticMatchingService($client),
            new EnrichedMatchingService(new CandidateSkillInferenceService($client), new SemanticMatchingService($client)),
            $skillRepository,
            $technologyRepository,
            $positionRepository,
        );

        $skillRepository->expects(self::once())->method('findAll')->willReturn([]);
        $technologyRepository->expects(self::once())->method('findAll')->willReturn([]);
        $positionRepository->expects(self::once())->method('findAll')->willReturn([]);
        $client
            ->expects(self::exactly(2))
            ->method('embedBatch')
            ->willReturnOnConsecutiveCalls(
                [['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'A']],
                [['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'B']],
            );

        $offerA = (new JobOffer())->setTitle('A')->setDescription('A');
        $offerB = (new JobOffer())->setTitle('B')->setDescription('B');

        $payload = $service->buildOfferMatches([$offerA, $offerB], []);

        self::assertCount(2, $payload);
        self::assertSame('A', $payload[0]['offer']['title']);
        self::assertSame('B', $payload[1]['offer']['title']);
    }

    /**
     * @return array{0: DeveloperProfile, 1: DeveloperProfile}
     */
    private function createDevelopers(): array
    {
        $phpSkill = (new Skill())->setName('PHP')->setCategory('Backend');
        $communicationSkill = (new Skill())->setName('Communication')->setCategory('Soft Skills');
        $symfony = (new Technology())->setName('Symfony')->setCategory('Backend');
        $react = (new Technology())->setName('React')->setCategory('Frontend');
        $apiPosition = (new Position())->setName('API development');

        $alice = (new DeveloperProfile())
            ->setFirstName('Alice')
            ->setLastName('Dupont')
            ->setHeadline('Développeuse PHP Symfony')
            ->setBio('Très bonne communication alice@example.com')
            ->setYearsExperience(2)
            ->setSlug('alice');
        $this->setEntityId($alice, 101);
        $alice->addProfileSkill((new ProfileSkill())->setSkill($phpSkill));
        $alice->addProfileSkill((new ProfileSkill())->setSkill($communicationSkill));
        $aliceExperience = (new Experience())
            ->setCompanyName('DevSpot')
            ->setTitle('Backend Developer')
            ->setDescription('API Symfony')
            ->setStartDate(new \DateTime('2020-01-01'))
            ->setEndDate(new \DateTime('2021-01-01'))
            ->setIsCurrent(false)
            ->addTechnology($symfony);
        $alice->addExperience($aliceExperience);
        $alice->addDesiredPosition($apiPosition);

        $bob = (new DeveloperProfile())
            ->setFirstName('Bob')
            ->setLastName('Martin')
            ->setHeadline('Développeur React')
            ->setBio('Frontend expert')
            ->setYearsExperience(5)
            ->setSlug('bob');
        $this->setEntityId($bob, 102);
        $bobExperience = (new Experience())
            ->setCompanyName('Acme')
            ->setTitle('Frontend Developer')
            ->setDescription('React dashboards Symfony')
            ->setStartDate(new \DateTime('2018-01-01'))
            ->setEndDate(new \DateTime('2020-01-01'))
            ->setIsCurrent(false)
            ->addTechnology($react);
        $bob->addExperience($bobExperience);

        return [$alice, $bob];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}