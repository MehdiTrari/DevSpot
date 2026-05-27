<?php

declare(strict_types=1);

namespace App\Tests\Matching\Service;

use App\Matching\Service\CvAnonymizer;
use PHPUnit\Framework\TestCase;

final class CvAnonymizerTest extends TestCase
{
    public function testItMasksEmailPhoneUrlsAndProvidedIdentityTerms(): void
    {
        $anonymizer = new CvAnonymizer();

        $content = 'John Doe can be reached at john.doe@example.com, +33 6 12 34 56 78, or https://portfolio.example.com/john-doe.';
        $anonymized = $anonymizer->anonymize($content, ['John', 'Doe']);

        self::assertStringNotContainsString('john.doe@example.com', $anonymized);
        self::assertStringNotContainsString('John', $anonymized);
        self::assertStringNotContainsString('Doe', $anonymized);
        self::assertStringNotContainsString('https://portfolio.example.com/john-doe', $anonymized);
        self::assertStringContainsString('[IDENTITY]', $anonymized);
        self::assertStringContainsString('[EMAIL]', $anonymized);
        self::assertStringContainsString('[PHONE]', $anonymized);
        self::assertStringContainsString('[URL]', $anonymized);
    }
}
