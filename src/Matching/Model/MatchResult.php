<?php

declare(strict_types=1);

namespace App\Matching\Model;

final readonly class MatchResult
{
    /**
     * @param array<string, float> $scoreBreakdown
     */
    public function __construct(
        public CandidateProfile $candidate,
        public float $score,
        public array $scoreBreakdown,
    ) {
    }
}
