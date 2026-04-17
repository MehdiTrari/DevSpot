<?php

declare(strict_types=1);

namespace App\Matching\Model;

final readonly class JobOffer
{
    /**
     * @param list<string> $requiredHardSkills
     * @param list<string> $desiredSoftSkills
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $requiredHardSkills,
        public array $desiredSoftSkills,
        public string $description,
    ) {
    }
}
