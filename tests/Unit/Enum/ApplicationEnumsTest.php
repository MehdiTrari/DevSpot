<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\CompanySize;
use App\Enum\ContractType;
use App\Enum\ConversationStatus;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use App\Enum\NotificationType;
use App\Enum\SkillLevel;
use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

final class ApplicationEnumsTest extends TestCase
{
    public function testLabelEnumsExposeExpectedTranslationKeys(): void
    {
        self::assertSame('company_size.micro', CompanySize::MICRO->label());
        self::assertSame('company_size.enterprise', CompanySize::ENTERPRISE->label());
        self::assertSame('Ouverte', ConversationStatus::OPEN->label());
        self::assertSame('Bloquée', ConversationStatus::BLOCKED->label());
        self::assertSame('experience_level.intern', ExperienceLevel::INTERN->label());
        self::assertSame('experience_level.lead', ExperienceLevel::LEAD->label());
        self::assertSame('skill_level.beginner', SkillLevel::BEGINNER->label());
        self::assertSame('skill_level.expert', SkillLevel::EXPERT->label());
        self::assertSame('user_status.active', UserStatus::ACTIVE->label());
        self::assertSame('user_status.deleted', UserStatus::DELETED->label());
    }

    public function testSimpleEnumsExposeExpectedCasesAndValues(): void
    {
        self::assertSame(['remote', 'hybrid', 'onsite'], array_map(
            static fn (LocationType $locationType): string => $locationType->value,
            LocationType::cases(),
        ));

        self::assertSame(
            ['full_time', 'part_time', 'permanent', 'fixed_term', 'internship', 'apprenticeship', 'freelance', 'contract'],
            array_map(static fn (ContractType $contractType): string => $contractType->value, ContractType::cases()),
        );
    }

    public function testNotificationTypeContainsExpectedBusinessCases(): void
    {
        $cases = array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases());

        self::assertContains('account_pending', $cases);
        self::assertContains('new_message', $cases);
        self::assertContains('profile_incomplete', $cases);
        self::assertContains('job_offer_expired', $cases);
        self::assertContains('support_request', $cases);
        self::assertContains('system_notification', $cases);
    }
}
