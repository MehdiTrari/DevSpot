<?php

namespace App\Enum;

enum OfferStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case CLOSED = 'closed';
}
