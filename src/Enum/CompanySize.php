<?php

namespace App\Enum;

enum CompanySize: string
{
    case MICRO = 'micro';             // 1–9 employés
    case SMALL = 'small';             // 10–49 employés
    case MEDIUM = 'medium';           // 50–249 employés
    case LARGE = 'large';             // 250–999 employés
    case ENTERPRISE = 'enterprise';   // 1000+ employés

    public function label(): string
    {
        return 'company_size.' . $this->value;
    }
}
