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
        ], $payload);
    }
}