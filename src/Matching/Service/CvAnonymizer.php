<?php

declare(strict_types=1);

namespace App\Matching\Service;

final class CvAnonymizer
{
    public function anonymize(string $rawCv): string
    {
        $patterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[EMAIL]',
            '/\b\d{1,3}\s+[^,\n]+(?:rue|avenue|av\.|boulevard|bd\.|road|street)\b/i' => '[ADDRESS]',
            '/\+?[0-9][0-9\s().-]{7,}/' => '[PHONE]',
        ];

        $anonymized = $rawCv;

        foreach ($patterns as $pattern => $replacement) {
            $anonymized = (string) preg_replace($pattern, $replacement, $anonymized);
        }

        return $anonymized;
    }
}
