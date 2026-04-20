<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Service\AiMatchingClientInterface;
use App\Service\CandidateSkillInferenceService;
use App\Service\EnrichedMatchingService;
use App\Service\SemanticMatchingService;
use PHPUnit\Framework\TestCase;

final class SemanticAndEnrichedMatchingServicesTest extends TestCase
{
    public function testSemanticMatchingServiceScoresTextsWithCosineRescaling(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $client
            ->expects(self::once())
            ->method('embedBatch')
            ->with(['Offer text', 'Candidate text'])
            ->willReturn([
                ['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'offer'],
                ['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'candidate'],
            ]);

        $service = new SemanticMatchingService($client);

        self::assertSame([
            'available' => true,
            'score' => 1.0,
            'percentage' => 100.0,
            'dimension' => 2,
        ], $service->scoreTexts('Offer text', 'Candidate text'));
    }

    public function testSemanticMatchingServiceReturnsUnavailableWhenOfferEmbeddingIsMissing(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $client
            ->expects(self::once())
            ->method('embedBatch')
            ->willReturn([null, ['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'candidate']]);

        $service = new SemanticMatchingService($client);

        self::assertSame([
            'available' => false,
            'scores' => [
                'cand-1' => ['score' => null, 'percentage' => null, 'dimension' => null],
            ],
        ], $service->scoreTextMap('offer', ['cand-1' => 'candidate']));
    }

    public function testEnrichedMatchingServiceAppliesInferenceBonusAndCapsFinalScore(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $inferenceService = new CandidateSkillInferenceService($client);
        $semanticService = new SemanticMatchingService($client);

        $candidate = new CandidateProfile('cand-1', 3, ['PHP'], ['Teamwork'], 'CV with Symfony');
        $offer = new JobOffer('offer-1', 'Backend', ['Symfony'], ['Communication'], 'Symfony role');

        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(['CV with Symfony'])
            ->willReturn([[
                'inferredSoftSkills' => ['Communication'],
                'inferredTransferableSkills' => ['mentoring'],
                'inferredTechnicalSkills' => [
                    ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 1.0],
                ],
                'confidence' => ['Communication' => 1.0],
                'enrichedText' => 'enriched cv',
                'normalizedText' => 'normalized cv',
            ]]);

        $service = new EnrichedMatchingService($inferenceService, $semanticService);
        $result = $service->scoreCandidates($offer, [$candidate], [
            'cand-1' => ['score' => 0.95, 'percentage' => 95.0, 'dimension' => 384],
        ]);

        self::assertSame([
            'available' => true,
            'scores' => [
                'cand-1' => [
                    'score' => 1.0,
                    'percentage' => 100.0,
                    'dimension' => 384,
                    'inferredSoftSkills' => ['Communication'],
                    'inferredTransferableSkills' => ['mentoring'],
                    'inferredTechnicalSkills' => [
                        ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 1.0],
                    ],
                    'confidence' => ['Communication' => 1.0],
                    'enrichedText' => "CV with Symfony\n\nInferred skills: Communication mentoring Symfony (advanced)",
                ],
            ],
        ], $result);
    }

    public function testEnrichedMatchingServiceFallsBackToSemanticServiceWhenRawScoresAreMissing(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $inferenceService = new CandidateSkillInferenceService($client);
        $semanticService = new SemanticMatchingService($client);

        $candidate = new CandidateProfile('cand-2', 1, ['React'], [], 'raw cv');
        $offer = new JobOffer('offer-2', 'Frontend', ['React'], [], 'react role');

        $client
            ->expects(self::once())
            ->method('embedBatch')
            ->with(['Frontend react role', 'raw cv'])
            ->willReturn([
                ['embedding' => [1.0, 0.0], 'dimension' => 128, 'normalizedText' => 'offer'],
                ['embedding' => [0.875, 0.4841229183], 'dimension' => 128, 'normalizedText' => 'candidate'],
            ]);

        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(['raw cv'])
            ->willReturn([[
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => [],
                'inferredTechnicalSkills' => [],
                'confidence' => [],
                'enrichedText' => 'raw cv',
                'normalizedText' => 'raw cv',
            ]]);

        $service = new EnrichedMatchingService($inferenceService, $semanticService);
        $result = $service->scoreCandidates($offer, [$candidate]);

        self::assertSame(0.5, $result['scores']['cand-2']['score']);
        self::assertSame('raw cv', $result['scores']['cand-2']['enrichedText']);
    }
}