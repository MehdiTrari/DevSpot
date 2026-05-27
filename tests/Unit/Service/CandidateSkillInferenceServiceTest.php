<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AiMatchingClientInterface;
use App\Service\CandidateSkillInferenceService;
use PHPUnit\Framework\TestCase;

final class CandidateSkillInferenceServiceTest extends TestCase
{
    public function testInferFromTextReturnsUnavailableFallbackWhenAiIsUnavailable(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $client->expects(self::once())->method('inferSkills')->with('raw cv')->willReturn(null);

        $service = new CandidateSkillInferenceService($client);

        self::assertSame([
            'available' => false,
            'inferredSoftSkills' => [],
            'inferredTransferableSkills' => [],
            'inferredTechnicalSkills' => [],
            'confidence' => [],
            'enrichedText' => 'raw cv',
            'normalizedText' => 'raw cv',
        ], $service->inferFromText('raw cv'));
    }

    public function testInferManyFromTextsUsesBatchResultsAndBuildsEnrichedText(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(['CV 1'])
            ->willReturn([[
                'inferredSoftSkills' => ['communication'],
                'inferredTransferableSkills' => ['problem solving'],
                'inferredTechnicalSkills' => [
                    ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 0.8],
                ],
                'confidence' => ['communication' => 0.7],
                'normalizedText' => 'normalized cv 1',
            ]]);

        $service = new CandidateSkillInferenceService($client);
        $result = $service->inferManyFromTexts(['CV 1']);

        self::assertSame([[
            'available' => true,
            'inferredSoftSkills' => ['communication'],
            'inferredTransferableSkills' => ['problem solving'],
            'inferredTechnicalSkills' => [
                ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 0.8],
            ],
            'confidence' => ['communication' => 0.7],
            'enrichedText' => "CV 1\n\nInferred skills: communication problem solving Symfony (advanced)",
            'normalizedText' => 'normalized cv 1',
        ]], $result);
    }

    public function testInferManyFromTextsFallsBackToSingleInferenceWhenBatchShapeIsWrong(): void
    {
        $client = $this->createMock(AiMatchingClientInterface::class);
        $client
            ->expects(self::once())
            ->method('inferSkillsBatch')
            ->with(['A', 'B'])
            ->willReturn([['normalizedText' => 'only-one']]);
        $client
            ->expects(self::exactly(2))
            ->method('inferSkills')
            ->willReturnMap([
                ['A', null],
                ['B', [
                    'inferredSoftSkills' => [],
                    'inferredTransferableSkills' => ['adaptability'],
                    'inferredTechnicalSkills' => [],
                    'confidence' => [],
                    'normalizedText' => 'b',
                ]],
            ]);

        $service = new CandidateSkillInferenceService($client);

        self::assertSame([
            [
                'available' => false,
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => [],
                'inferredTechnicalSkills' => [],
                'confidence' => [],
                'enrichedText' => 'A',
                'normalizedText' => 'A',
            ],
            [
                'available' => true,
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => ['adaptability'],
                'inferredTechnicalSkills' => [],
                'confidence' => [],
                'enrichedText' => "B\n\nInferred skills: adaptability",
                'normalizedText' => 'b',
            ],
        ], $service->inferManyFromTexts(['A', 'B']));
    }
}