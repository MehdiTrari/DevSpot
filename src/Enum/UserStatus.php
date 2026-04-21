<?php

namespace App\Enum;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case PENDING = 'pending';
    case SUSPENDED = 'suspended';
    case BANNED = 'banned';
    case DELETED = 'deleted';

    public function label(): string
    {
        return 'user_status.'.$this->value;
    }
}
