<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Service\AiMatchingClientInterface;
use App\Service\CandidateSkillInferenceService;
use App\Service\EnrichedMatchingService;
use App\Service\SemanticMatchingService;
use PHPUnit\Framework\TestCase;

final class EnrichedMatchingServiceTest extends TestCase
{
    public function testItBuildsEnrichedScoresAndKeepsInferredSkills(): void
    {
        $client = new class implements AiMatchingClientInterface {
            public function health(): ?array
            {
                return null;
            }

            public function embed(string $text): ?array
            {
                return match (true) {
                    str_contains($text, 'Frontend Developer') => [
                        'embedding' => [1.0, 0.0],
                        'dimension' => 2,
                        'normalizedText' => $text,
                    ],
                    str_contains($text, 'teamwork') => [
                        'embedding' => [1.0, 0.0],
                        'dimension' => 2,
                        'normalizedText' => $text,
                    ],
                    default => [
                        'embedding' => [0.0, 1.0],
                        'dimension' => 2,
                        'normalizedText' => $text,
                    ],
                };
            }

            public function embedBatch(array $texts): ?array
            {
                return array_map(fn (string $text): array => $this->embed($text) ?? [], $texts);
            }

            public function match(string $offerText, string $candidateText): ?array
            {
                return null;
            }

            public function inferSkills(string $text): ?array
            {
                return match ($text) {
                    'Profil junior' => [
                        'inferredSoftSkills' => ['teamwork'],
                        'inferredTransferableSkills' => ['problem solving'],
                        'inferredTechnicalSkills' => [
                            ['skill' => 'react', 'level' => 'intermediate', 'confidence' => 0.82],
                        ],
                        'confidence' => ['teamwork' => 0.92, 'problem solving' => 0.73],
                        'normalizedText' => $text,
                    ],
                    default => [
                        'inferredSoftSkills' => [],
                        'inferredTransferableSkills' => [],
                        'inferredTechnicalSkills' => [],
                        'confidence' => [],
                        'normalizedText' => $text,
                    ],
                };
            }

            public function inferSkillsBatch(array $texts): ?array
            {
                return array_map(fn (string $text): array => $this->inferSkills($text) ?? [], $texts);
            }
        };

        $service = new EnrichedMatchingService(
            new CandidateSkillInferenceService($client),
            new SemanticMatchingService($client),
        );

        $offer = new JobOffer('offer-1', 'Frontend Developer', ['react'], ['communication'], 'Design system et interfaces.');
        $candidates = [
            new CandidateProfile('cand-1', 1, [], [], 'Profil junior'),
            new CandidateProfile('cand-2', 4, [], [], 'Profil data'),
        ];

        $result = $service->scoreCandidates($offer, $candidates);

        self::assertTrue($result['available']);
        self::assertSame(['teamwork'], $result['scores']['cand-1']['inferredSoftSkills']);
        self::assertSame('react', $result['scores']['cand-1']['inferredTechnicalSkills'][0]['skill']);
        self::assertSame(4.3, $result['scores']['cand-1']['percentage']);
        self::assertSame(0.0, $result['scores']['cand-2']['percentage']);
    }
}
