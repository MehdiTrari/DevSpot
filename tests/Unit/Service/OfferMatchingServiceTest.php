<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeveloperProfile;
use App\Entity\Education;
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
use App\Service\CandidateProfileEmbeddingService;
use App\Service\CandidateSkillInferenceService;
use App\Service\CandidateTextPreprocessor;
use App\Service\EnrichedMatchingService;
use App\Service\OfferMatchingService;
use App\Service\SemanticMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OfferMatchingServiceTest extends TestCase
{
    public function testBuildSingleOfferMatchExtractsSkillsBuildsPayloadAndSortsMatches(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            new CandidateTextPreprocessor(),
            new CandidateProfileEmbeddingService($client, new CandidateTextPreprocessor(), $entityManager),
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
            ->expects(self::exactly(2))
            ->method('embedBatch')
            ->willReturnCallback(static function (array $texts): array {
                if (1 === count($texts)) {
                    return [
                        ['embedding' => [1.0, 0.0], 'dimension' => 256, 'normalizedText' => 'offer'],
                    ];
                }

                return array_map(static function (string $text): array {
                    if (str_contains($text, 'Alice') || str_contains($text, 'communication')) {
                        return ['embedding' => [0.875, 0.4841229183], 'dimension' => 256, 'normalizedText' => 'alice'];
                    }

                    return ['embedding' => [1.0, 0.0], 'dimension' => 256, 'normalizedText' => 'bob'];
                }, $texts);
            });
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
        self::assertStringContainsString('Core skills:', $payload['matches'][1]['anonymizedCv']);
        self::assertStringContainsString('Experience:', $payload['matches'][1]['anonymizedCv']);
        self::assertStringContainsString('Education:', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('alice@example.com', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('DevSpot', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('Supinfo', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('Alice', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('Dupont', $payload['matches'][1]['anonymizedCv']);
        self::assertStringNotContainsString('alice', $payload['matches'][1]['anonymizedCv']);
        self::assertTrue($payload['semantic']['available']);
        self::assertTrue($payload['enriched']['available']);
    }

    public function testBuildOfferMatchesMapsEachOffer(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            new CandidateTextPreprocessor(),
            new CandidateProfileEmbeddingService($client, new CandidateTextPreprocessor(), $entityManager),
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
            ->expects(self::never())
            ->method('embedBatch');

        $offerA = (new JobOffer())->setTitle('A')->setDescription('A');
        $offerB = (new JobOffer())->setTitle('B')->setDescription('B');

        $payload = $service->buildOfferMatches([$offerA, $offerB], []);

        self::assertCount(2, $payload);
        self::assertSame('A', $payload[0]['offer']['title']);
        self::assertSame('B', $payload[1]['offer']['title']);
    }

    public function testBuildSingleOfferMatchLimitsSemanticAndEnrichedScoringToTopKBaselineCandidates(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            new CandidateTextPreprocessor(),
            new CandidateProfileEmbeddingService($client, new CandidateTextPreprocessor(), $entityManager),
            new SemanticMatchingService($client),
            new EnrichedMatchingService(new CandidateSkillInferenceService($client), new SemanticMatchingService($client)),
            $skillRepository,
            $technologyRepository,
            $positionRepository,
            1,
        );

        $offer = (new JobOffer())
            ->setTitle('Développeur React')
            ->setDescription('React et communication')
            ->setExperienceLevel(2);

        $reactSkill = (new Skill())->setName('React')->setCategory('Frontend');
        $communicationSkill = (new Skill())->setName('Communication')->setCategory('Soft Skills');

        $skillRepository->expects(self::once())->method('findAll')->willReturn([$communicationSkill]);
        $technologyRepository->expects(self::once())->method('findAll')->willReturn([(new Technology())->setName('React')->setCategory('Frontend')]);
        $positionRepository->expects(self::once())->method('findAll')->willReturn([]);

        $top = (new DeveloperProfile())
            ->setFirstName('Top')
            ->setLastName('Candidate')
            ->setHeadline('Développeur React')
            ->setBio('React communication')
            ->setYearsExperience(4)
            ->setSlug('top-candidate');
        $this->setEntityId($top, 201);
        $top->addProfileSkill((new ProfileSkill())->setSkill($reactSkill));
        $top->addProfileSkill((new ProfileSkill())->setSkill($communicationSkill));

        $mid = (new DeveloperProfile())
            ->setFirstName('Mid')
            ->setLastName('Candidate')
            ->setHeadline('Développeur frontend')
            ->setBio('Communication')
            ->setYearsExperience(2)
            ->setSlug('mid-candidate');
        $this->setEntityId($mid, 202);
        $mid->addProfileSkill((new ProfileSkill())->setSkill($communicationSkill));

        $low = (new DeveloperProfile())
            ->setFirstName('Low')
            ->setLastName('Candidate')
            ->setHeadline('Développeur backend')
            ->setBio('PHP only')
            ->setYearsExperience(5)
            ->setSlug('low-candidate');
        $this->setEntityId($low, 203);

        $client
            ->expects(self::exactly(2))
            ->method('embedBatch')
            ->willReturnCallback(function (array $texts): array {
                if (1 === count($texts)) {
                    if (str_starts_with($texts[0], 'Headline: ')) {
                        self::assertStringContainsString('Headline: Développeur React', $texts[0]);

                        return [
                            ['embedding' => [1.0, 0.0], 'dimension' => 64, 'normalizedText' => 'top'],
                        ];
                    }

                    self::assertSame('Développeur React React et communication', $texts[0]);

                    return [
                        ['embedding' => [1.0, 0.0], 'dimension' => 64, 'normalizedText' => 'offer'],
                    ];
                }

                self::fail('Unexpected embedBatch payload shape.');
            });

        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(self::callback(function (array $texts): bool {
                self::assertCount(1, $texts);
                self::assertStringContainsString('Headline: Développeur React', $texts[0]);

                return true;
            }))
            ->willReturn([
                [
                    'inferredSoftSkills' => [],
                    'inferredTransferableSkills' => [],
                    'inferredTechnicalSkills' => [],
                    'confidence' => [],
                    'normalizedText' => 'top',
                ],
            ]);

        $payload = $service->buildSingleOfferMatch($offer, [$top, $mid, $low]);

        self::assertCount(3, $payload['matches']);
        self::assertSame('Top Candidate', $payload['matches'][0]['fullName']);
        self::assertSame(100.0, $payload['matches'][0]['semanticPercentage']);
        self::assertSame(100.0, $payload['matches'][0]['semanticEnrichedPercentage']);
        self::assertNull($payload['matches'][1]['semanticPercentage']);
        self::assertNull($payload['matches'][1]['semanticEnrichedPercentage']);
        self::assertNull($payload['matches'][2]['semanticPercentage']);
        self::assertNull($payload['matches'][2]['semanticEnrichedPercentage']);
        self::assertTrue($payload['semantic']['available']);
        self::assertTrue($payload['enriched']['available']);
    }

    public function testBuildSingleOfferMatchUsesStoredEmbeddingRetrievalBeforeReranking(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $skillRepository = $this->createMock(SkillRepository::class);
        $technologyRepository = $this->createMock(TechnologyRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $preprocessor = new CandidateTextPreprocessor();

        $service = new OfferMatchingService(
            new SkillMatcher(),
            new FairnessAuditor(),
            new CvAnonymizer(),
            $preprocessor,
            new CandidateProfileEmbeddingService($client, $preprocessor, $entityManager),
            new SemanticMatchingService($client),
            new EnrichedMatchingService(new CandidateSkillInferenceService($client), new SemanticMatchingService($client)),
            $skillRepository,
            $technologyRepository,
            $positionRepository,
            1,
        );

        $offer = (new JobOffer())
            ->setTitle('Développeur React')
            ->setDescription('React et communication')
            ->setExperienceLevel(2);

        $reactSkill = (new Skill())->setName('React')->setCategory('Frontend');
        $communicationSkill = (new Skill())->setName('Communication')->setCategory('Soft Skills');

        $skillRepository->expects(self::once())->method('findAll')->willReturn([$communicationSkill]);
        $technologyRepository->expects(self::once())->method('findAll')->willReturn([(new Technology())->setName('React')->setCategory('Frontend')]);
        $positionRepository->expects(self::once())->method('findAll')->willReturn([]);

        $baselineTop = (new DeveloperProfile())
            ->setFirstName('Baseline')
            ->setLastName('Top')
            ->setHeadline('Développeur React')
            ->setBio('React communication')
            ->setYearsExperience(4)
            ->setSlug('baseline-top');
        $this->setEntityId($baselineTop, 301);
        $baselineTop->addProfileSkill((new ProfileSkill())->setSkill($reactSkill));
        $baselineTop->addProfileSkill((new ProfileSkill())->setSkill($communicationSkill));

        $retrievalTop = (new DeveloperProfile())
            ->setFirstName('Vector')
            ->setLastName('Top')
            ->setHeadline('Développeur frontend')
            ->setBio('Communication')
            ->setYearsExperience(2)
            ->setSlug('vector-top');
        $this->setEntityId($retrievalTop, 302);
        $retrievalTop->addProfileSkill((new ProfileSkill())->setSkill($communicationSkill));

        $baselineTop
            ->setMatchingEmbedding([0.0, 1.0])
            ->setMatchingEmbeddingDimension(2)
            ->setMatchingEmbeddingTextHash(hash('sha256', $preprocessor->buildCandidateText($baselineTop)))
            ->setMatchingEmbeddingUpdatedAt(new \DateTimeImmutable());
        $retrievalTop
            ->setMatchingEmbedding([1.0, 0.0])
            ->setMatchingEmbeddingDimension(2)
            ->setMatchingEmbeddingTextHash(hash('sha256', $preprocessor->buildCandidateText($retrievalTop)))
            ->setMatchingEmbeddingUpdatedAt(new \DateTimeImmutable());

        $client
            ->expects(self::exactly(2))
            ->method('embedBatch')
            ->willReturnCallback(static function (array $texts): array {
                self::assertCount(1, $texts);
                self::assertSame('Développeur React React et communication', $texts[0]);

                return [
                    ['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'offer'],
                ];
            });

        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(self::callback(function (array $texts): bool {
                self::assertCount(1, $texts);
                self::assertStringContainsString('Headline: Développeur frontend', $texts[0]);
                self::assertStringNotContainsString('Headline: Développeur React', $texts[0]);

                return true;
            }))
            ->willReturn([
                [
                    'inferredSoftSkills' => [],
                    'inferredTransferableSkills' => [],
                    'inferredTechnicalSkills' => [],
                    'confidence' => [],
                    'normalizedText' => 'vector',
                ],
            ]);

        $payload = $service->buildSingleOfferMatch($offer, [$baselineTop, $retrievalTop]);

        self::assertCount(2, $payload['matches']);
        self::assertSame('Vector Top', $payload['matches'][0]['fullName']);
        self::assertSame(100.0, $payload['matches'][0]['semanticPercentage']);
        self::assertSame(100.0, $payload['matches'][0]['semanticEnrichedPercentage']);
        self::assertSame('Baseline Top', $payload['matches'][1]['fullName']);
        self::assertNull($payload['matches'][1]['semanticPercentage']);
        self::assertTrue($payload['semantic']['available']);
        self::assertTrue($payload['enriched']['available']);
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
        $alice->addEducation(
            (new Education())
                ->setSchoolName('Supinfo')
                ->setDegree('Master')
                ->setField('Software Engineering')
                ->setDescription('Advanced API Platform and PostgreSQL projects.')
        );

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
