<?php

declare(strict_types=1);

namespace App\Matching\Service;

use App\Matching\Model\MatchResult;

final class FairnessAuditor
{
    private const FAVORABLE_SCORE_THRESHOLD = 0.8;

    /**
     * @param list<array{yearsOfExperience?: int|null, score?: float|int|null}> $candidates
     *
     * @return array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float, junior_count: int, non_junior_count: int, junior_selection_rate: float, non_junior_selection_rate: float, selection_rate_ratio: float, score_gap: float, assessment: string}
     */
    public function auditCandidateScores(array $candidates): array
    {
        $juniorScores = [];
        $nonJuniorScores = [];

        foreach ($candidates as $candidate) {
            $score = $candidate['score'] ?? null;
            if (!is_int($score) && !is_float($score)) {
                continue;
            }

            $yearsOfExperience = (int) ($candidate['yearsOfExperience'] ?? 0);
            if ($yearsOfExperience <= 2) {
                $juniorScores[] = (float) $score;

                continue;
            }

            $nonJuniorScores[] = (float) $score;
        }

        return $this->buildAuditPayload($juniorScores, $nonJuniorScores);
    }

    /**
     * @param list<MatchResult> $results
     *
     * @return array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float, junior_count: int, non_junior_count: int, junior_selection_rate: float, non_junior_selection_rate: float, selection_rate_ratio: float, score_gap: float, assessment: string}
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

        return $this->buildAuditPayload($juniorScores, $nonJuniorScores);
    }

    /**
     * @param list<float> $juniorScores
     * @param list<float> $nonJuniorScores
     *
     * @return array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float, junior_count: int, non_junior_count: int, junior_selection_rate: float, non_junior_selection_rate: float, selection_rate_ratio: float, score_gap: float, assessment: string}
     */
    private function buildAuditPayload(array $juniorScores, array $nonJuniorScores): array
    {
        $juniorAvg = $this->average($juniorScores);
        $nonJuniorAvg = $this->average($nonJuniorScores);
        $juniorSelectionRate = $this->selectionRate($juniorScores);
        $nonJuniorSelectionRate = $this->selectionRate($nonJuniorScores);
        $selectionRateRatio = $nonJuniorSelectionRate > 0.0 ? $juniorSelectionRate / $nonJuniorSelectionRate : 1.0;
        $disparateImpactRatio = $nonJuniorAvg > 0 ? $juniorAvg / $nonJuniorAvg : 1.0;

        return [
            'junior_avg_score' => round($juniorAvg, 4),
            'non_junior_avg_score' => round($nonJuniorAvg, 4),
            'disparate_impact_ratio' => round($disparateImpactRatio, 4),
            'junior_count' => count($juniorScores),
            'non_junior_count' => count($nonJuniorScores),
            'junior_selection_rate' => round($juniorSelectionRate, 4),
            'non_junior_selection_rate' => round($nonJuniorSelectionRate, 4),
            'selection_rate_ratio' => round($selectionRateRatio, 4),
            'score_gap' => round($juniorAvg - $nonJuniorAvg, 4),
            'assessment' => $this->assessment($juniorScores, $nonJuniorScores, $selectionRateRatio),
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

    /**
     * @param list<float> $values
     */
    private function selectionRate(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        $selected = array_filter(
            $values,
            static fn (float $score): bool => $score >= self::FAVORABLE_SCORE_THRESHOLD,
        );

        return count($selected) / count($values);
    }

    /**
     * @param list<float> $juniorScores
     * @param list<float> $nonJuniorScores
     */
    private function assessment(array $juniorScores, array $nonJuniorScores, float $selectionRateRatio): string
    {
        if ([] === $juniorScores || [] === $nonJuniorScores) {
            return 'insufficient_comparison_population';
        }

        if ($selectionRateRatio < 0.8) {
            return 'junior_under_selected';
        }

        if ($selectionRateRatio > 1.25) {
            return 'junior_over_selected';
        }

        return 'balanced_selection_rate';
    }
}
