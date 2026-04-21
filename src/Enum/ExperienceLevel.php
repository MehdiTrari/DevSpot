<?php

namespace App\Enum;

enum ExperienceLevel: string
{
    case INTERN = 'intern';
    case JUNIOR = 'junior';
    case MID = 'mid';
    case SENIOR = 'senior';
    case LEAD = 'lead';

    public function label(): string
    {
        return 'experience_level.'.$this->value;
    }
}
