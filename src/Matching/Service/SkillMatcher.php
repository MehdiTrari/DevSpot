<?php

declare(strict_types=1);

namespace App\Matching\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Matching\Model\MatchResult;

final class SkillMatcher
{
    /**
     * @param list<CandidateProfile> $candidates
     *
     * @return list<MatchResult>
     */
    public function rankCandidates(JobOffer $offer, array $candidates): array
    {
        $results = array_map(fn (CandidateProfile $candidate): MatchResult => $this->score($offer, $candidate), $candidates);

        usort(
            $results,
            static fn (MatchResult $left, MatchResult $right): int => $right->score <=> $left->score,
        );

        return $results;
    }

    public function score(JobOffer $offer, CandidateProfile $candidate): MatchResult
    {
        $hardSkillsScore = $this->overlapScore($offer->requiredHardSkills, $candidate->hardSkills);
        $softSkillsScore = $this->overlapScore($offer->desiredSoftSkills, $candidate->softSkills);

        // Bonus assumé pour les profils juniors afin de limiter le biais expérience.
        $juniorBoost = $candidate->yearsOfExperience <= 2 ? 0.1 : 0.0;

        // Pondérations calibrables, à remplacer ensuite par des embeddings sémantiques.
        $score = min(1.0, ($hardSkillsScore * 0.65) + ($softSkillsScore * 0.25) + $juniorBoost);

        return new MatchResult(
            $candidate,
            round($score, 4),
            [
                'hard_skills' => round($hardSkillsScore, 4),
                'soft_skills' => round($softSkillsScore, 4),
                'junior_boost' => round($juniorBoost, 4),
            ],
        );
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function overlapScore(array $expected, array $actual): float
    {
        if ([] === $expected) {
            return 1.0;
        }

        $normalizedExpected = array_values(array_unique(array_map('mb_strtolower', $expected)));
        $normalizedActual = array_values(array_unique(array_map('mb_strtolower', $actual)));

        $matches = array_intersect($normalizedExpected, $normalizedActual);

        return count($matches) / count($normalizedExpected);
    }
}
