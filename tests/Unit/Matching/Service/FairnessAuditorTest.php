<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\MatchResult;
use App\Matching\Service\FairnessAuditor;
use PHPUnit\Framework\TestCase;

final class FairnessAuditorTest extends TestCase
{
    public function testAuditCandidateScoresBuildsExpectedAveragesAndRatio(): void
    {
        $auditor = new FairnessAuditor();

        $payload = $auditor->auditCandidateScores([
            ['yearsOfExperience' => 1, 'score' => 0.6],
            ['yearsOfExperience' => 2, 'score' => 0.8],
            ['yearsOfExperience' => 6, 'score' => 0.5],
            ['yearsOfExperience' => 8, 'score' => 1.0],
            ['yearsOfExperience' => 4, 'score' => null],
            ['yearsOfExperience' => 1, 'score' => 'invalid'],
        ]);

        self::assertSame([
            'junior_avg_score' => 0.7,
            'non_junior_avg_score' => 0.75,
            'disparate_impact_ratio' => 0.9333,
            'junior_count' => 2,
            'non_junior_count' => 2,
            'junior_selection_rate' => 0.5,
            'non_junior_selection_rate' => 0.5,
            'selection_rate_ratio' => 1.0,
            'score_gap' => -0.05,
            'assessment' => 'balanced_selection_rate',
        ], $payload);
    }

    public function testAuditJuniorBiasHandlesOnlyJuniorPopulation(): void
    {
        $auditor = new FairnessAuditor();

        $results = [
            new MatchResult(new CandidateProfile('cand-1', 0, [], [], 'CV'), 0.7, []),
            new MatchResult(new CandidateProfile('cand-2', 2, [], [], 'CV'), 0.9, []),
        ];

        $payload = $auditor->auditJuniorBias($results);

        self::assertSame([
            'junior_avg_score' => 0.8,
            'non_junior_avg_score' => 0.0,
            'disparate_impact_ratio' => 1.0,
            'junior_count' => 2,
            'non_junior_count' => 0,
            'junior_selection_rate' => 0.5,
            'non_junior_selection_rate' => 0.0,
            'selection_rate_ratio' => 1.0,
            'score_gap' => 0.8,
            'assessment' => 'insufficient_comparison_population',
        ], $payload);
    }

    public function testAuditCandidateScoresFlagsJuniorUnderSelection(): void
    {
        $auditor = new FairnessAuditor();

        $payload = $auditor->auditCandidateScores([
            ['yearsOfExperience' => 1, 'score' => 0.7],
            ['yearsOfExperience' => 2, 'score' => 0.75],
            ['yearsOfExperience' => 5, 'score' => 0.85],
            ['yearsOfExperience' => 7, 'score' => 0.9],
        ]);

        self::assertSame('junior_under_selected', $payload['assessment']);
        self::assertSame(0.0, $payload['junior_selection_rate']);
        self::assertSame(1.0, $payload['non_junior_selection_rate']);
        self::assertSame(0.0, $payload['selection_rate_ratio']);
        self::assertSame(-0.15, $payload['score_gap']);
    }
}
