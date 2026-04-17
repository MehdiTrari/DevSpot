<?php

declare(strict_types=1);

namespace App\Tests\Matching\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Service\AiMatchingClientInterface;
use App\Service\SemanticMatchingService;
use PHPUnit\Framework\TestCase;

final class SemanticMatchingServiceTest extends TestCase
{
    public function testItComputesSemanticScoresFromEmbeddings(): void
    {
        $service = new SemanticMatchingService(new class implements AiMatchingClientInterface {
            public function health(): ?array
            {
                return ['status' => 'ok', 'model' => 'camembert-base', 'dimension' => 2];
            }

            public function embed(string $text): ?array
            {
                return match ($text) {
                    'Offre Symfony API' => [
                        'embedding' => [1.0, 0.0],
                        'dimension' => 2,
                        'normalizedText' => $text,
                    ],
                    'Profil Symfony' => [
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

            public function match(string $offerText, string $candidateText): ?array
            {
                return null;
            }

            public function inferSkills(string $text): ?array
            {
                return null;
            }

            public function embedBatch(array $texts): ?array
            {
                return null;
            }

            public function inferSkillsBatch(array $texts): ?array
            {
                return null;
            }
        });

        $offer = new JobOffer('offer-1', 'Offre', [], [], 'Symfony API');
        $candidates = [
            new CandidateProfile('cand-1', 1, [], [], 'Profil Symfony'),
            new CandidateProfile('cand-2', 4, [], [], 'Profil Java'),
        ];

        $scores = $service->scoreCandidates($offer, $candidates);

        self::assertTrue($scores['available']);
        self::assertSame(1.0, $scores['scores']['cand-1']['score']);
        self::assertSame(0.0, $scores['scores']['cand-2']['percentage']);
    }

    public function testItMarksSemanticScoreUnavailableWhenEmbeddingFails(): void
    {
        $service = new SemanticMatchingService(new class implements AiMatchingClientInterface {
            public function health(): ?array
            {
                return null;
            }

            public function embed(string $text): ?array
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

            public function embedBatch(array $texts): ?array
            {
                return null;
            }

            public function inferSkillsBatch(array $texts): ?array
            {
                return null;
            }
        });

        $offer = new JobOffer('offer-1', 'Offre', [], [], 'Symfony API');
        $result = $service->scoreCandidates($offer, [new CandidateProfile('cand-1', 1, [], [], 'Profil Symfony')]);

        self::assertFalse($result['available']);
        self::assertNull($result['scores']['cand-1']['score']);
    }
}
