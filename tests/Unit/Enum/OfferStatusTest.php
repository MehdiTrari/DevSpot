<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\OfferStatus;
use PHPUnit\Framework\TestCase;

final class OfferStatusTest extends TestCase
{
    public function testEnumValues(): void
    {
        self::assertSame('draft', OfferStatus::DRAFT->value);
        self::assertSame('published', OfferStatus::PUBLISHED->value);
        self::assertSame('closed', OfferStatus::CLOSED->value);
    }

    public function testEnumHasThreeCases(): void
    {
        self::assertCount(3, OfferStatus::cases());
    }

    public function testEnumFromString(): void
    {
        self::assertSame(OfferStatus::DRAFT, OfferStatus::from('draft'));
        self::assertSame(OfferStatus::PUBLISHED, OfferStatus::from('published'));
        self::assertSame(OfferStatus::CLOSED, OfferStatus::from('closed'));
    }

    public function testEnumTryFromInvalidReturnsNull(): void
    {
        self::assertNull(OfferStatus::tryFrom('invalid'));
    }
}
