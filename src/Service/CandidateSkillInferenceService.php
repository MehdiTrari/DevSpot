<?php

declare(strict_types=1);

namespace App\Service;

final class CandidateSkillInferenceService
{
    public function __construct(
        private readonly AiMatchingClientInterface $aiMatchingClient,
    ) {
    }

    /**
     * @return array{available: bool, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string, normalizedText: string}
     */
    public function inferFromText(string $text): array
    {
        return $this->mapResponseToResult($text, $this->aiMatchingClient->inferSkills($text));
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{available: bool, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string, normalizedText: string}>
     */
    public function inferManyFromTexts(array $texts): array
    {
        if ([] === $texts) {
            return [];
        }

        $responses = $this->aiMatchingClient->inferSkillsBatch($texts);
        if (is_array($responses) && count($responses) === count($texts)) {
            $results = [];
            foreach ($texts as $index => $text) {
                $results[] = $this->mapResponseToResult($text, $responses[$index]);
            }

            return $results;
        }

        return array_map(fn (string $text): array => $this->inferFromText($text), $texts);
    }

    /**
     * @param array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, normalizedText: string}|null $response
     *
     * @return array{available: bool, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string, normalizedText: string}
     */
    private function mapResponseToResult(string $text, ?array $response): array
    {
        if (null === $response) {
            return $this->unavailableResult($text);
        }

        $enrichedText = $this->buildEnrichedText(
            $text,
            $response['inferredSoftSkills'],
            $response['inferredTransferableSkills'],
            $response['inferredTechnicalSkills'],
        );

        return [
            'available' => true,
            'inferredSoftSkills' => $response['inferredSoftSkills'],
            'inferredTransferableSkills' => $response['inferredTransferableSkills'],
            'inferredTechnicalSkills' => $response['inferredTechnicalSkills'],
            'confidence' => $response['confidence'],
            'enrichedText' => $enrichedText,
            'normalizedText' => $response['normalizedText'],
        ];
    }

    /**
     * @return array{available: bool, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string, normalizedText: string}
     */
    private function unavailableResult(string $text): array
    {
        return [
            'available' => false,
            'inferredSoftSkills' => [],
            'inferredTransferableSkills' => [],
            'inferredTechnicalSkills' => [],
            'confidence' => [],
            'enrichedText' => $text,
            'normalizedText' => $text,
        ];
    }

    /**
     * @param list<string>                                                 $inferredSoftSkills
     * @param list<string>                                                 $inferredTransferableSkills
     * @param list<array{skill: string, level: string, confidence: float}> $inferredTechnicalSkills
     */
    private function buildEnrichedText(string $text, array $inferredSoftSkills, array $inferredTransferableSkills, array $inferredTechnicalSkills): string
    {
        $technicalContext = implode(' ', array_map(
            static fn (array $skill): string => sprintf('%s (%s)', $skill['skill'], $skill['level']),
            $inferredTechnicalSkills,
        ));
        $skillContext = trim(implode(' ', [
            implode(' ', $inferredSoftSkills),
            implode(' ', $inferredTransferableSkills),
            $technicalContext,
        ]));

        return trim(implode("\n\n", array_filter([
            trim($text),
            '' !== $skillContext ? 'Inferred skills: ' . $skillContext : null,
        ])));
    }
}
