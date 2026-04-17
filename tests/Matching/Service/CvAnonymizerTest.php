<?php

declare(strict_types=1);

namespace App\Tests\Matching\Service;

use App\Matching\Service\CvAnonymizer;
use PHPUnit\Framework\TestCase;

final class CvAnonymizerTest extends TestCase
{
    public function testItMasksEmailAndPhone(): void
    {
        $anonymizer = new CvAnonymizer();

        $content = 'Contact me at john.doe@example.com or +33 6 12 34 56 78.';
        $anonymized = $anonymizer->anonymize($content);

        self::assertStringNotContainsString('john.doe@example.com', $anonymized);
        self::assertStringContainsString('[EMAIL]', $anonymized);
        self::assertStringContainsString('[PHONE]', $anonymized);
    }
}
