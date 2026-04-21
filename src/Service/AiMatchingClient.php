<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiMatchingClient implements AiMatchingClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly string $baseUrl,
        private readonly float $timeout,
    ) {
    }

    public function health(): ?array
    {
        $payload = $this->request('GET', '/health');
        if (null === $payload) {
            return null;
        }

        $status = $payload['status'] ?? null;
        $model = $payload['model'] ?? null;
        $dimension = $payload['dimension'] ?? null;

        if (!is_string($status) || !is_string($model) || !is_numeric($dimension)) {
            return null;
        }

        return [
            'status' => $status,
            'model' => $model,
            'dimension' => (int) $dimension,
        ];
    }

    public function embed(string $text): ?array
    {
        return $this->embedBatch([$text])[0] ?? null;
    }

    public function embedBatch(array $texts): ?array
    {
        return $this->fetchCachedBatch(
            $texts,
            'embed',
            fn (array $missingTexts): ?array => $this->request('POST', '/embed-batch', ['texts' => $missingTexts]),
            fn (array $payload): ?array => $this->normalizeBatchEmbedPayload($payload),
            fn (string $text): ?array => $this->normalizeEmbedPayload($this->request('POST', '/embed', ['text' => $text])),
        );
    }

    public function match(string $offerText, string $candidateText): ?array
    {
        $payload = $this->request('POST', '/match', [
            'offer_text' => $offerText,
            'candidate_text' => $candidateText,
        ]);
        if (null === $payload) {
            return null;
        }

        $semanticScore = $payload['semantic_score'] ?? null;
        $offerDimension = $payload['offer_dimension'] ?? null;
        $candidateDimension = $payload['candidate_dimension'] ?? null;
        $normalizedOfferText = $payload['normalized_offer_text'] ?? null;
        $normalizedCandidateText = $payload['normalized_candidate_text'] ?? null;

        if (!is_numeric($semanticScore) || !is_numeric($offerDimension) || !is_numeric($candidateDimension) || !is_string($normalizedOfferText) || !is_string($normalizedCandidateText)) {
            return null;
        }

        return [
            'semanticScore' => (float) $semanticScore,
            'offerDimension' => (int) $offerDimension,
            'candidateDimension' => (int) $candidateDimension,
            'normalizedOfferText' => $normalizedOfferText,
            'normalizedCandidateText' => $normalizedCandidateText,
        ];
    }

    public function inferSkills(string $text): ?array
    {
        return $this->inferSkillsBatch([$text])[0] ?? null;
    }

    public function inferSkillsBatch(array $texts): ?array
    {
        return $this->fetchCachedBatch(
            $texts,
            'infer',
            fn (array $missingTexts): ?array => $this->request('POST', '/infer-skills-batch', ['texts' => $missingTexts]),
            fn (array $payload): ?array => $this->normalizeBatchInferencePayload($payload),
            fn (string $text): ?array => ($r = $this->request('POST', '/infer-skills', ['text' => $text])) !== null ? $this->normalizeInferencePayload($r) : null,
        );
    }

    /**
     * @param list<string>                                                      $texts
     * @param callable(list<string>): ?array<string, mixed>                     $batchFetcher
     * @param callable(array<string, mixed>): ?array<int, array<string, mixed>> $batchNormalizer
     * @param callable(string): ?array<string, mixed>                           $singleFetcher
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchCachedBatch(array $texts, string $prefix, callable $batchFetcher, callable $batchNormalizer, callable $singleFetcher): ?array
    {
        if ([] === $texts) {
            return [];
        }

        $results = array_fill(0, count($texts), null);
        $missingTexts = [];
        $missingIndexes = [];

        foreach (array_values($texts) as $index => $text) {
            $item = $this->cache->getItem($this->cacheKey($prefix, $text));
            if ($item->isHit()) {
                $cached = $item->get();
                if (!is_array($cached)) {
                    return null;
                }

                $results[$index] = $cached;
                continue;
            }

            $missingTexts[] = $text;
            $missingIndexes[] = $index;
        }

        if ([] !== $missingTexts) {
            $batchPayload = $batchFetcher($missingTexts);
            $normalizedBatch = is_array($batchPayload) ? $batchNormalizer($batchPayload) : null;

            if (is_array($normalizedBatch) && count($normalizedBatch) === count($missingTexts)) {
                foreach ($normalizedBatch as $position => $value) {
                    $text = $missingTexts[$position];
                    $index = $missingIndexes[$position];
                    $results[$index] = $value;
                    $this->saveCacheValue($this->cacheKey($prefix, $text), $value);
                }
            } else {
                foreach ($missingTexts as $position => $text) {
                    $value = $singleFetcher($text);
                    if (null === $value) {
                        return null;
                    }

                    $index = $missingIndexes[$position];
                    $results[$index] = $value;
                    $this->saveCacheValue($this->cacheKey($prefix, $text), $value);
                }
            }
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                return null;
            }
        }

        return $results;
    }

    private function cacheKey(string $prefix, string $text): string
    {
        return sprintf('ai_matching.%s.%s', $prefix, hash('sha256', $text));
    }

    /**
     * @param array<string, mixed> $value
     */
    private function saveCacheValue(string $key, array $value): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $item->expiresAfter(604800);
        $this->cache->save($item);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{embedding: list<float>, dimension: int, normalizedText: string}|null
     */
    private function normalizeEmbedPayload(?array $payload): ?array
    {
        if (null === $payload) {
            return null;
        }

        $embedding = $payload['embedding'] ?? null;
        $dimension = $payload['dimension'] ?? null;
        $normalizedText = $payload['normalized_text'] ?? null;

        if (!is_array($embedding) || !is_numeric($dimension) || !is_string($normalizedText)) {
            return null;
        }

        $vector = [];
        foreach ($embedding as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $vector[] = (float) $value;
        }

        return [
            'embedding' => $vector,
            'dimension' => (int) $dimension,
            'normalizedText' => $normalizedText,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{embedding: list<float>, dimension: int, normalizedText: string}>|null
     */
    private function normalizeBatchEmbedPayload(array $payload): ?array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        $embeddings = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                return null;
            }

            $normalized = $this->normalizeEmbedPayload($item);
            if (null === $normalized) {
                return null;
            }

            $embeddings[] = $normalized;
        }

        return $embeddings;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, normalizedText: string}>|null
     */
    private function normalizeBatchInferencePayload(array $payload): ?array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                return null;
            }

            $normalized = $this->normalizeInferencePayload($item);
            if (null === $normalized) {
                return null;
            }

            $results[] = $normalized;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, normalizedText: string}|null
     */
    private function normalizeInferencePayload(array $payload): ?array
    {
        $inferredSoftSkills = $payload['inferred_soft_skills'] ?? null;
        $inferredTransferableSkills = $payload['inferred_transferable_skills'] ?? null;
        $inferredTechnicalSkills = $payload['inferred_technical_skills'] ?? null;
        $confidence = $payload['confidence'] ?? null;
        $normalizedText = $payload['normalized_text'] ?? null;

        if (!is_array($inferredSoftSkills) || !is_array($inferredTransferableSkills) || !is_array($inferredTechnicalSkills) || !is_array($confidence) || !is_string($normalizedText)) {
            return null;
        }

        $softSkills = [];
        foreach ($inferredSoftSkills as $skill) {
            if (!is_string($skill)) {
                return null;
            }

            $softSkills[] = $skill;
        }

        $transferableSkills = [];
        foreach ($inferredTransferableSkills as $skill) {
            if (!is_string($skill)) {
                return null;
            }

            $transferableSkills[] = $skill;
        }

        $technicalSkills = [];
        foreach ($inferredTechnicalSkills as $technicalSkill) {
            if (!is_array($technicalSkill)) {
                return null;
            }

            $skill = $technicalSkill['skill'] ?? null;
            $level = $technicalSkill['level'] ?? null;
            $technicalConfidence = $technicalSkill['confidence'] ?? null;
            if (!is_string($skill) || !is_string($level) || !is_numeric($technicalConfidence)) {
                return null;
            }

            $technicalSkills[] = [
                'skill' => $skill,
                'level' => $level,
                'confidence' => (float) $technicalConfidence,
            ];
        }

        $confidenceMap = [];
        foreach ($confidence as $skill => $score) {
            if (!is_string($skill) || !is_numeric($score)) {
                return null;
            }

            $confidenceMap[$skill] = (float) $score;
        }

        return [
            'inferredSoftSkills' => $softSkills,
            'inferredTransferableSkills' => $transferableSkills,
            'inferredTechnicalSkills' => $technicalSkills,
            'confidence' => $confidenceMap,
            'normalizedText' => $normalizedText,
        ];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $json = []): ?array
    {
        $options = [
            'timeout' => $this->timeout,
        ];

        if ([] !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('AI matching request failed.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($statusCode >= 400 || !is_array($payload)) {
            $this->logger->warning('AI matching service returned an invalid response.', [
                'path' => $path,
                'statusCode' => $statusCode,
            ]);

            return null;
        }

        return $payload;
    }
}
