<?php

declare(strict_types=1);

namespace App\Service;

interface AiMatchingClientInterface
{
    /**
     * @return array{status: string, model: string, dimension: int, rerankerAvailable?: bool, rerankerModel?: ?string}|null
     */
    public function health(): ?array;

    /**
     * @return array{embedding: list<float>, dimension: int, normalizedText: string}|null
     */
    public function embed(string $text): ?array;

    /**
     * @param list<string> $texts
     *
     * @return list<array{embedding: list<float>, dimension: int, normalizedText: string}>|null
     */
    public function embedBatch(array $texts): ?array;

    /**
     * @return array{semanticScore: float, offerDimension: int, candidateDimension: int, normalizedOfferText: string, normalizedCandidateText: string}|null
     */
    public function match(string $offerText, string $candidateText): ?array;

    /**
     * @return array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, normalizedText: string}|null
     */
    public function inferSkills(string $text): ?array;

    /**
     * @param list<string> $texts
     *
     * @return list<array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, normalizedText: string}>|null
     */
    public function inferSkillsBatch(array $texts): ?array;

    /**
     * @param list<array{candidateId: string, features: list<float>}> $items
     *
     * @return array<string, float>|null
     */
    public function rerank(array $items): ?array;
}
