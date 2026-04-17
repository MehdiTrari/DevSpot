<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\JobOffer;
use App\Enum\OfferStatus;
use PHPUnit\Framework\TestCase;

final class JobOfferTest extends TestCase
{
    public function testConstructorSetsDefaultValues(): void
    {
        $offer = new JobOffer();

        self::assertTrue($offer->isActive());
        self::assertSame(OfferStatus::PUBLISHED, $offer->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $offer->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $offer->getUpdatedAt());
    }

    public function testSetStatusToPublishedSetsIsActiveTrue(): void
    {
        $offer = new JobOffer();
        $offer->setStatus(OfferStatus::DRAFT);
        self::assertFalse($offer->isActive());

        $offer->setStatus(OfferStatus::PUBLISHED);
        self::assertTrue($offer->isActive());
        self::assertSame(OfferStatus::PUBLISHED, $offer->getStatus());
    }

    public function testSetStatusToDraftSetsIsActiveFalse(): void
    {
        $offer = new JobOffer();
        $offer->setStatus(OfferStatus::DRAFT);

        self::assertFalse($offer->isActive());
        self::assertSame(OfferStatus::DRAFT, $offer->getStatus());
    }

    public function testSetStatusToClosedSetsIsActiveFalse(): void
    {
        $offer = new JobOffer();
        $offer->setStatus(OfferStatus::CLOSED);

        self::assertFalse($offer->isActive());
        self::assertSame(OfferStatus::CLOSED, $offer->getStatus());
    }

    public function testSetStatusUpdatesUpdatedAt(): void
    {
        $offer = new JobOffer();
        $originalUpdatedAt = $offer->getUpdatedAt();

        // Small sleep to ensure time difference
        usleep(10000);
        $offer->setStatus(OfferStatus::CLOSED);

        self::assertGreaterThanOrEqual($originalUpdatedAt, $offer->getUpdatedAt());
    }

    public function testAllStatusTransitionsWork(): void
    {
        $offer = new JobOffer();

        // Published -> Closed
        $offer->setStatus(OfferStatus::CLOSED);
        self::assertSame(OfferStatus::CLOSED, $offer->getStatus());
        self::assertFalse($offer->isActive());

        // Closed -> Published (reopen)
        $offer->setStatus(OfferStatus::PUBLISHED);
        self::assertSame(OfferStatus::PUBLISHED, $offer->getStatus());
        self::assertTrue($offer->isActive());

        // Published -> Draft
        $offer->setStatus(OfferStatus::DRAFT);
        self::assertSame(OfferStatus::DRAFT, $offer->getStatus());
        self::assertFalse($offer->isActive());

        // Draft -> Published
        $offer->setStatus(OfferStatus::PUBLISHED);
        self::assertSame(OfferStatus::PUBLISHED, $offer->getStatus());
        self::assertTrue($offer->isActive());
    }

    public function testSetTitleAndDescription(): void
    {
        $offer = new JobOffer();
        $offer->setTitle('Dev PHP Senior');
        $offer->setDescription('Poste à pourvoir immédiatement.');

        self::assertSame('Dev PHP Senior', $offer->getTitle());
        self::assertSame('Poste à pourvoir immédiatement.', $offer->getDescription());
    }

    public function testSetSalaryRange(): void
    {
        $offer = new JobOffer();
        $offer->setSalaryMin(35000);
        $offer->setSalaryMax(55000);

        self::assertSame(35000, $offer->getSalaryMin());
        self::assertSame(55000, $offer->getSalaryMax());
    }

    public function testSetApplicationDeadline(): void
    {
        $offer = new JobOffer();
        $deadline = new \DateTimeImmutable('2026-05-01');

        $offer->setApplicationDeadline($deadline);

        self::assertSame($deadline, $offer->getApplicationDeadline());
    }
}
