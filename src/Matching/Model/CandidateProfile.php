<?php

declare(strict_types=1);

namespace App\Matching\Model;

final readonly class CandidateProfile
{
    /**
     * @param list<string> $hardSkills
     * @param list<string> $softSkills
     */
    public function __construct(
        public string $id,
        public int $yearsOfExperience,
        public array $hardSkills,
        public array $softSkills,
        public string $rawCv,
    ) {
    }
}
