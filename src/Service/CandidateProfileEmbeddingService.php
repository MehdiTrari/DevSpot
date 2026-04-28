<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeveloperProfile;
use Doctrine\ORM\EntityManagerInterface;

final class CandidateProfileEmbeddingService
{
    private const DEFAULT_BATCH_SIZE = 10;

    public function __construct(
        private readonly AiMatchingClientInterface $aiMatchingClient,
        private readonly CandidateTextPreprocessor $candidateTextPreprocessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
    }

    /**
     * @param list<DeveloperProfile> $profiles
     *
     * @return array{processed: int, refreshed: int, skipped: int, failed: int}
     */
    public function refreshEmbeddings(array $profiles, bool $force = false, bool $flush = true, ?int $batchSize = null): array
    {
        $stats = [
            'processed' => count($profiles),
            'refreshed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $resolvedBatchSize = $batchSize !== null && $batchSize > 0
            ? $batchSize
            : ($this->batchSize > 0 ? $this->batchSize : self::DEFAULT_BATCH_SIZE);

        $profilesToRefresh = [];
        $textsToRefresh = [];
        $hashesByKey = [];

        foreach ($profiles as $profile) {
            $matchingText = $this->candidateTextPreprocessor->buildCandidateText($profile);
            $textHash = hash('sha256', $matchingText);
            $profileKey = $this->profileKey($profile);

            if (!$force && $this->hasFreshStoredEmbedding($profile, $textHash)) {
                ++$stats['skipped'];
                continue;
            }

            $profilesToRefresh[$profileKey] = $profile;
            $textsToRefresh[$profileKey] = $matchingText;
            $hashesByKey[$profileKey] = $textHash;
        }

        if ([] === $profilesToRefresh) {
            return $stats;
        }

        $orderedKeys = array_keys($profilesToRefresh);
        foreach (array_chunk($orderedKeys, $resolvedBatchSize) as $keyChunk) {
            $textChunk = array_values(array_map(
                static fn (string $key): string => $textsToRefresh[$key],
                $keyChunk,
            ));
            $batchEmbeddings = $this->aiMatchingClient->embedBatch($textChunk);

            foreach ($keyChunk as $index => $profileKey) {
                $profile = $profilesToRefresh[$profileKey];
                $matchingText = $textsToRefresh[$profileKey];
                $embedding = is_array($batchEmbeddings) && count($batchEmbeddings) === count($textChunk)
                    ? ($batchEmbeddings[$index] ?? null)
                    : null;

                if (!is_array($embedding)) {
                    $embedding = $this->aiMatchingClient->embed($matchingText);
                }

                $normalizedEmbedding = $this->normalizeEmbeddingPayload($embedding);
                if (null === $normalizedEmbedding) {
                    ++$stats['failed'];
                    continue;
                }

                $profile
                    ->setMatchingEmbedding($normalizedEmbedding['embedding'])
                    ->setMatchingEmbeddingDimension($normalizedEmbedding['dimension'])
                    ->setMatchingEmbeddingTextHash($hashesByKey[$profileKey])
                    ->setMatchingEmbeddingUpdatedAt(new \DateTimeImmutable());

                ++$stats['refreshed'];
            }
        }

        if ($flush && $stats['refreshed'] > 0) {
            $this->entityManager->flush();
        }

        return $stats;
    }

    /**
     * @param list<DeveloperProfile> $profiles
     *
     * @return array<string, array{embedding: list<float>, dimension: int}>
     */
    public function storedEmbeddingsForProfiles(array $profiles): array
    {
        $embeddings = [];

        foreach ($profiles as $profile) {
            $matchingText = $this->candidateTextPreprocessor->buildCandidateText($profile);
            $textHash = hash('sha256', $matchingText);

            if (!$this->hasFreshStoredEmbedding($profile, $textHash)) {
                continue;
            }

            $normalizedEmbedding = $this->normalizeStoredEmbedding($profile);
            if (null === $normalizedEmbedding) {
                continue;
            }

            $embeddings[$this->profileKey($profile)] = $normalizedEmbedding;
        }

        return $embeddings;
    }

    private function profileKey(DeveloperProfile $profile): string
    {
        return (string) ($profile->getId() ?? spl_object_id($profile));
    }

    private function hasFreshStoredEmbedding(DeveloperProfile $profile, string $textHash): bool
    {
        return null !== $profile->getMatchingEmbedding()
            && null !== $profile->getMatchingEmbeddingDimension()
            && $profile->getMatchingEmbeddingTextHash() === $textHash;
    }

    /**
     * @param array{embedding?: mixed, dimension?: mixed}|null $embedding
     *
     * @return array{embedding: list<float>, dimension: int}|null
     */
    private function normalizeEmbeddingPayload(?array $embedding): ?array
    {
        if (!is_array($embedding)) {
            return null;
        }

        $vector = $embedding['embedding'] ?? null;
        $dimension = $embedding['dimension'] ?? null;

        if (!is_array($vector) || !is_numeric($dimension) || [] === $vector) {
            return null;
        }

        $normalizedVector = [];
        foreach ($vector as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $normalizedVector[] = (float) $value;
        }

        return [
            'embedding' => $normalizedVector,
            'dimension' => (int) $dimension,
        ];
    }

    /**
     * @return array{embedding: list<float>, dimension: int}|null
     */
    private function normalizeStoredEmbedding(DeveloperProfile $profile): ?array
    {
        return $this->normalizeEmbeddingPayload([
            'embedding' => $profile->getMatchingEmbedding(),
            'dimension' => $profile->getMatchingEmbeddingDimension(),
        ]);
    }
}
