<?php

declare(strict_types=1);

namespace App\Matching\Service;

use App\Matching\Model\MatchResult;

final class FairnessAuditor
{
    /**
     * @param list<MatchResult> $results
     *
     * @return array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float}
     */
    public function auditJuniorBias(array $results): array
    {
        $juniorScores = [];
        $nonJuniorScores = [];

        foreach ($results as $result) {
            if ($result->candidate->yearsOfExperience <= 2) {
                $juniorScores[] = $result->score;

                continue;
            }

            $nonJuniorScores[] = $result->score;
        }

        $juniorAvg = $this->average($juniorScores);
        $nonJuniorAvg = $this->average($nonJuniorScores);

        return [
            'junior_avg_score' => round($juniorAvg, 4),
            'non_junior_avg_score' => round($nonJuniorAvg, 4),
            'disparate_impact_ratio' => round($nonJuniorAvg > 0 ? $juniorAvg / $nonJuniorAvg : 1.0, 4),
        ];
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }
}
