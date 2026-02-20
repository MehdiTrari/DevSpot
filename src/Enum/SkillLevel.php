<?php

namespace App\Enum;

enum SkillLevel: string
{
    case BEGINNER = 'beginner';
    case INTERMEDIATE = 'intermediate';
    case ADVANCED = 'advanced';
    case EXPERT = 'expert';

    public function label(): string
    {
        return 'skill_level.' . $this->value;
    }
}