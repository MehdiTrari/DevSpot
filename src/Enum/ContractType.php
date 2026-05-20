<?php

namespace App\Enum;

enum ContractType: string
{
    case FULL_TIME = 'full_time';
    case PART_TIME = 'part_time';
    case PERMANENT = 'permanent';      // CDI
    case FIXED_TERM = 'fixed_term';    // CDD
    case INTERNSHIP = 'internship';
    case APPRENTICESHIP = 'apprenticeship';
    case FREELANCE = 'freelance';
    case CONTRACT = 'contract';
}
