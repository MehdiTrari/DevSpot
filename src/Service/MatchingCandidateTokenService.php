<?php

declare(strict_types=1);

namespace App\Service;

final class MatchingCandidateTokenService
{
    public function __construct(
        private readonly string $secret,
    ) {
    }

    public function generateToken(int $offerId, int $developerId, int $recruiterUserId): string
    {
        $payload = $this->encode([
            'offerId' => $offerId,
            'developerId' => $developerId,
            'recruiterUserId' => $recruiterUserId,
        ]);
        $signature = $this->sign($payload);

        return $payload . '.' . $signature;
    }

    /**
     * @return array{offerId: int, developerId: int, recruiterUserId: int}|null
     */
    public function parseToken(string $token): ?array
    {
        $separator = strrpos($token, '.');
        if (false === $separator) {
            return null;
        }

        $payload = substr($token, 0, $separator);
        $signature = substr($token, $separator + 1);
        if ('' === $payload || '' === $signature) {
            return null;
        }

        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $decoded = $this->decode($payload);
        if (
            !is_array($decoded)
            || !isset($decoded['offerId'], $decoded['developerId'], $decoded['recruiterUserId'])
            || !is_numeric($decoded['offerId'])
            || !is_numeric($decoded['developerId'])
            || !is_numeric($decoded['recruiterUserId'])
        ) {
            return null;
        }

        return [
            'offerId' => (int) $decoded['offerId'],
            'developerId' => (int) $decoded['developerId'],
            'recruiterUserId' => (int) $decoded['recruiterUserId'],
        ];
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    /**
     * @param array<string, int>|string $value
     */
    private function encode(array|string $value): string
    {
        $raw = is_array($value)
            ? json_encode($value, JSON_THROW_ON_ERROR)
            : $value;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $value): ?array
    {
        $padding = strlen($value) % 4;
        if (0 !== $padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            return null;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }
}
