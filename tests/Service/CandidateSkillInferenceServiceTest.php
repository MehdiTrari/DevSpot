<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AiMatchingClientInterface;
use App\Service\CandidateSkillInferenceService;
use PHPUnit\Framework\TestCase;

final class CandidateSkillInferenceServiceTest extends TestCase
{
    public function testItReturnsInferredSkillsAndEnrichedText(): void
    {
        $service = new CandidateSkillInferenceService(new class implements AiMatchingClientInterface {
            public function health(): ?array
            {
                return null;
            }

            public function embed(string $text): ?array
            {
                return null;
            }

            public function embedBatch(array $texts): ?array
            {
                return null;
            }

            public function match(string $offerText, string $candidateText): ?array
            {
                return null;
            }

            public function inferSkills(string $text): ?array
            {
                return [
                    'inferredSoftSkills' => ['teamwork', 'communication'],
                    'inferredTransferableSkills' => ['problem solving'],
                    'inferredTechnicalSkills' => [
                        ['skill' => 'docker', 'level' => 'beginner', 'confidence' => 0.74],
                    ],
                    'confidence' => ['teamwork' => 0.92, 'communication' => 0.81, 'problem solving' => 0.73],
                    'normalizedText' => $text,
                ];
            }

            public function inferSkillsBatch(array $texts): ?array
            {
                return array_map(fn (string $text): array => $this->inferSkills($text) ?? [], $texts);
            }
        });

        $result = $service->inferFromText('Projet de groupe avec soutenance et correction de bugs.');

        self::assertTrue($result['available']);
        self::assertSame(['teamwork', 'communication'], $result['inferredSoftSkills']);
        self::assertSame(['problem solving'], $result['inferredTransferableSkills']);
        self::assertSame('docker', $result['inferredTechnicalSkills'][0]['skill']);
        self::assertStringContainsString('Inferred skills:', $result['enrichedText']);
    }

    public function testItFallsBackWhenInferenceIsUnavailable(): void
    {
        $service = new CandidateSkillInferenceService(new class implements AiMatchingClientInterface {
            public function health(): ?array
            {
                return null;
            }

            public function embed(string $text): ?array
            {
                return null;
            }

            public function embedBatch(array $texts): ?array
            {
                return null;
            }

            public function match(string $offerText, string $candidateText): ?array
            {
                return null;
            }

            public function inferSkills(string $text): ?array
            {
                return null;
            }

            public function inferSkillsBatch(array $texts): ?array
            {
                return null;
            }
        });

        $result = $service->inferFromText('Texte libre');

        self::assertFalse($result['available']);
        self::assertSame([], $result['inferredSoftSkills']);
        self::assertSame('Texte libre', $result['enrichedText']);
    }
}
