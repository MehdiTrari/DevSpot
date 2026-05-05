<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\BenchmarkMatchingCommand;
use PHPUnit\Framework\TestCase;

final class BenchmarkMatchingCommandTest extends TestCase
{
    public function testExtractJuniorTopFiveStatsCountsJuniorProfilesInTopFive(): void
    {
        $command = (new \ReflectionClass(BenchmarkMatchingCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BenchmarkMatchingCommand::class, 'extractJuniorTopFiveStats');
        $method->setAccessible(true);

        $stats = $method->invoke($command, [
            ['yearsExperience' => 1],
            ['yearsExperience' => 4],
            ['yearsExperience' => 2],
            ['yearsExperience' => 6],
            ['yearsExperience' => 0],
            ['yearsExperience' => 1],
        ]);

        self::assertSame([
            'juniorCountTop5' => 3,
            'hasJuniorTop5' => true,
            'juniorTop1' => true,
        ], $stats);
    }

    public function testAggregateJuniorTopFiveStatsBuildsExpectedRates(): void
    {
        $command = (new \ReflectionClass(BenchmarkMatchingCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BenchmarkMatchingCommand::class, 'aggregateJuniorTopFiveStats');
        $method->setAccessible(true);

        $stats = $method->invoke($command, [
            'offer-1' => [
                'juniorCountTop5' => 2,
                'hasJuniorTop5' => true,
                'juniorTop1' => false,
            ],
            'offer-2' => [
                'juniorCountTop5' => 0,
                'hasJuniorTop5' => false,
                'juniorTop1' => false,
            ],
            'offer-3' => [
                'juniorCountTop5' => 1,
                'hasJuniorTop5' => true,
                'juniorTop1' => true,
            ],
        ]);

        self::assertSame([
            'junior_share_top5' => 0.2,
            'offers_with_junior_top5_rate' => 0.6666666666666666,
            'junior_top1_rate' => 0.3333333333333333,
        ], $stats);
    }
}