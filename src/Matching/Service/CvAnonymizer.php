<?php

declare(strict_types=1);

namespace App\Matching\Service;

final class CvAnonymizer
{
    /**
     * @param list<string> $termsToMask
     */
    public function anonymize(string $rawCv, array $termsToMask = []): string
    {
        $patterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[EMAIL]',
            '/\b\d{1,3}\s+[^,\n]+(?:rue|avenue|av\.|boulevard|bd\.|road|street)\b/i' => '[ADDRESS]',
            '/\+?[0-9][0-9\s().-]{7,}/' => '[PHONE]',
            '~https?://[^\s<>"\']+|www\.[^\s<>"\']+~i' => '[URL]',
        ];

        $anonymized = $rawCv;

        foreach ($patterns as $pattern => $replacement) {
            $anonymized = (string) preg_replace($pattern, $replacement, $anonymized);
        }

        foreach ($termsToMask as $term) {
            $trimmed = trim($term);
            if ('' === $trimmed) {
                continue;
            }

            $quoted = preg_quote($trimmed, '/');
            $pattern = preg_match('/\s/u', $trimmed)
                ? '/' . $quoted . '/iu'
                : '/\b' . $quoted . '\b/iu';

            $anonymized = (string) preg_replace($pattern, '[IDENTITY]', $anonymized);
        }

        return $anonymized;
    }
}
