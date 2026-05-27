<?php

namespace App\Enum;

enum LocationType: string
{
    case REMOTE = 'remote';
    case HYBRID = 'hybrid';
    case ONSITE = 'onsite';
}
