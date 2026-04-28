<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\SkillMatcher;
use PHPUnit\Framework\TestCase;

final class CvAnonymizerAndSkillMatcherTest extends TestCase
{
    public function testCvAnonymizerMasksSensitivePersonalData(): void
    {
        $anonymizer = new CvAnonymizer();

        $rawCv = <<<'TEXT'
Alice Martin
alice.dev@example.com
+33 6 12 34 56 78
12 rue de la Paix
Disponible pour un poste Symfony.
TEXT;

        $anonymized = $anonymizer->anonymize($rawCv, ['Alice', 'Martin']);

        self::assertStringNotContainsString('alice.dev@example.com', $anonymized);
        self::assertStringNotContainsString('+33 6 12 34 56 78', $anonymized);
        self::assertStringNotContainsString('12 rue de la Paix', $anonymized);
        self::assertStringNotContainsString('Alice', $anonymized);
        self::assertStringNotContainsString('Martin', $anonymized);
        self::assertStringContainsString('[EMAIL]', $anonymized);
        self::assertStringContainsString('[PHONE]', $anonymized);
        self::assertStringContainsString('[ADDRESS]', $anonymized);
        self::assertStringContainsString('[IDENTITY]', $anonymized);
        self::assertStringContainsString('Disponible pour un poste Symfony.', $anonymized);
    }

    public function testSkillMatcherScoresUsingCaseInsensitiveOverlapAndJuniorBoost(): void
    {
        $matcher = new SkillMatcher();
        $offer = new JobOffer(
            'offer-1',
            'Backend Symfony',
            ['PHP', 'Symfony', 'PHP'],
            ['Communication', 'Teamwork', 'Communication'],
            'API Platform et Symfony',
        );

        $juniorCandidate = new CandidateProfile(
            'candidate-1',
            1,
            ['php', 'symfony', 'symfony'],
            ['teamwork'],
            'CV',
        );

        $result = $matcher->score($offer, $juniorCandidate);

        self::assertSame($juniorCandidate, $result->candidate);
        self::assertSame(0.875, $result->score);
        self::assertSame([
            'hard_skills' => 1.0,
            'soft_skills' => 0.5,
            'junior_boost' => 0.1,
        ], $result->scoreBreakdown);
    }

    public function testSkillMatcherRanksCandidatesByDescendingScoreAndCapsMaximumScore(): void
    {
        $matcher = new SkillMatcher();
        $offer = new JobOffer(
            'offer-2',
            'Fullstack',
            [],
            [],
            'Tout profil',
        );

        $juniorCandidate = new CandidateProfile('candidate-junior', 0, ['PHP'], ['Communication'], 'CV');
        $seniorCandidate = new CandidateProfile('candidate-senior', 5, ['PHP'], ['Communication'], 'CV');

        $results = $matcher->rankCandidates($offer, [$seniorCandidate, $juniorCandidate]);

        self::assertCount(2, $results);
        self::assertSame('candidate-junior', $results[0]->candidate->id);
        self::assertSame(1.0, $results[0]->score);
        self::assertSame('candidate-senior', $results[1]->candidate->id);
        self::assertSame(0.9, $results[1]->score);
    }
}
