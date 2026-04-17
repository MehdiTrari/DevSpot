<?php

declare(strict_types=1);

namespace App\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;

final class SemanticMatchingService
{
    public function __construct(
        private readonly AiMatchingClientInterface $aiMatchingClient,
    ) {
    }

    /**
     * @param list<CandidateProfile> $candidates
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float, dimension: ?int}>}
     */
    public function scoreCandidates(JobOffer $offer, array $candidates): array
    {
        return $this->scoreTextMap(
            $this->buildOfferText($offer),
            array_reduce($candidates, function (array $carry, CandidateProfile $candidate): array {
                $carry[$candidate->id] = $candidate->rawCv;

                return $carry;
            }, []),
        );
    }

    /**
     * @param array<string, string> $candidateTextsById
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float, dimension: ?int}>}
     */
    public function scoreTextMap(string $offerText, array $candidateTextsById): array
    {
        $cache = [];
        $this->primeEmbeddings(array_merge([$offerText], array_values($candidateTextsById)), $cache);
        $offerEmbedding = $this->embeddingForText($offerText, $cache);

        $scores = [];
        if (null === $offerEmbedding) {
            foreach ($candidateTextsById as $candidateId => $_candidateText) {
                $scores[$candidateId] = [
                    'score' => null,
                    'percentage' => null,
                    'dimension' => null,
                ];
            }

            return [
                'available' => false,
                'scores' => $scores,
            ];
        }

        foreach ($candidateTextsById as $candidateId => $candidateText) {
            $candidateEmbedding = $this->embeddingForText($candidateText, $cache);

            if (null === $candidateEmbedding) {
                $scores[$candidateId] = [
                    'score' => null,
                    'percentage' => null,
                    'dimension' => null,
                ];

                continue;
            }

            $score = $this->cosineSimilarityScore($offerEmbedding['embedding'], $candidateEmbedding['embedding']);
            $scores[$candidateId] = [
                'score' => $score,
                'percentage' => round($score * 100, 1),
                'dimension' => $candidateEmbedding['dimension'],
            ];
        }

        return [
            'available' => true,
            'scores' => $scores,
        ];
    }

    /**
     * @return array{available: bool, score: ?float, percentage: ?float, dimension: ?int}
     */
    public function scoreTexts(string $offerText, string $candidateText): array
    {
        $cache = [];
        $this->primeEmbeddings([$offerText, $candidateText], $cache);
        $offerEmbedding = $this->embeddingForText($offerText, $cache);
        $candidateEmbedding = $this->embeddingForText($candidateText, $cache);

        if (null === $offerEmbedding || null === $candidateEmbedding) {
            return [
                'available' => false,
                'score' => null,
                'percentage' => null,
                'dimension' => null,
            ];
        }

        $score = $this->cosineSimilarityScore($offerEmbedding['embedding'], $candidateEmbedding['embedding']);

        return [
            'available' => true,
            'score' => $score,
            'percentage' => round($score * 100, 1),
            'dimension' => $candidateEmbedding['dimension'],
        ];
    }

    private function buildOfferText(JobOffer $offer): string
    {
        return trim(sprintf('%s %s', $offer->title, $offer->description));
    }

    /**
     * @param array<string, array{embedding: list<float>, dimension: int, normalizedText: string}|null> $cache
     *
     * @return array{embedding: list<float>, dimension: int, normalizedText: string}|null
     */
    private function embeddingForText(string $text, array &$cache): ?array
    {
        $cacheKey = hash('sha256', $text);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        return $cache[$cacheKey] = $this->aiMatchingClient->embed($text);
    }

    /**
     * @param list<string> $texts
     * @param array<string, array{embedding: list<float>, dimension: int, normalizedText: string}|null> $cache
     */
    private function primeEmbeddings(array $texts, array &$cache): void
    {
        $missingTexts = [];
        $missingKeys = [];

        foreach ($texts as $text) {
            $cacheKey = hash('sha256', $text);
            if (array_key_exists($cacheKey, $cache) || array_key_exists($cacheKey, $missingKeys)) {
                continue;
            }

            $missingKeys[$cacheKey] = $text;
            $missingTexts[] = $text;
        }

        if ([] === $missingTexts) {
            return;
        }

        $batchEmbeddings = $this->aiMatchingClient->embedBatch($missingTexts);
        if (is_array($batchEmbeddings) && count($batchEmbeddings) === count($missingTexts)) {
            foreach ($batchEmbeddings as $index => $embedding) {
                $cache[hash('sha256', $missingTexts[$index])] = $embedding;
            }

            return;
        }

        foreach ($missingTexts as $text) {
            $cache[hash('sha256', $text)] = $this->aiMatchingClient->embed($text);
        }
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    private function cosineSimilarityScore(array $left, array $right): float
    {
        if ([] === $left || [] === $right || count($left) !== count($right)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        foreach ($left as $index => $leftValue) {
            $rightValue = $right[$index];
            $dotProduct += $leftValue * $rightValue;
            $leftNorm += $leftValue ** 2;
            $rightNorm += $rightValue ** 2;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        $cosineSimilarity = $dotProduct / (sqrt($leftNorm) * sqrt($rightNorm));

        // Contrast-enhancing rescaling: raw cosine for French tech text
        // clusters in a narrow band (0.85–0.95).  Linear min-max stretching
        // with floor 0.75 spreads that range across 0–100 % so differences
        // between semantically close and distant profiles become visible.
        $floor = 0.75;
        $rescaled = ($cosineSimilarity - $floor) / (1.0 - $floor);

        return round(max(0.0, min(1.0, $rescaled)), 4);
    }
}
