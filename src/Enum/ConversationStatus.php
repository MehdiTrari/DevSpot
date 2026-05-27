<?php

namespace App\Enum;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case ARCHIVED = 'archived';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Ouverte',
            self::ARCHIVED => 'Archivée',
            self::BLOCKED => 'Bloquée',
        };
    }
}
