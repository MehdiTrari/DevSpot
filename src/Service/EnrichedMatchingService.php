<?php

declare(strict_types=1);

namespace App\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;

final class EnrichedMatchingService
{
    private const LEVEL_WEIGHTS = [
        'beginner' => 0.35,
        'intermediate' => 0.65,
        'advanced' => 0.85,
    ];

    /**
     * Semantic proximity map: when a candidate has an inferred skill (key),
     * a partial bonus is granted if the offer requires one of the related skills (values).
     */
    private const SKILL_PROXIMITY = [
        'javascript' => ['react', 'typescript', 'vue.js', 'angular', 'node.js', 'frontend developer'],
        'typescript' => ['react', 'node.js', 'frontend developer', 'full stack developer'],
        'html' => ['css', 'tailwind css', 'frontend developer'],
        'css' => ['html', 'tailwind css', 'frontend developer'],
        'react' => ['javascript', 'typescript', 'frontend developer', 'react developer', 'full stack developer'],
        'vue.js' => ['javascript', 'node.js', 'frontend developer', 'full stack developer'],
        'sql' => ['postgresql', 'mysql'],
        'postgresql' => ['sql', 'backend developer', 'full stack developer'],
        'mysql' => ['sql', 'backend developer', 'full stack developer'],
        'docker' => ['kubernetes', 'devops engineer'],
        'redis' => ['devops engineer', 'node.js', 'backend developer'],
        'linux' => ['bash', 'docker', 'devops engineer'],
        'bash' => ['linux', 'docker'],
        'symfony' => ['php', 'backend developer', 'symfony developer', 'full stack developer'],
        'twig' => ['symfony', 'php', 'frontend developer', 'full stack developer', 'symfony developer'],
        'doctrine' => ['symfony', 'php', 'sql', 'backend developer', 'symfony developer', 'full stack developer'],
        'api development' => ['php', 'symfony', 'node.js', 'backend developer', 'full stack developer', 'symfony developer', 'node.js developer'],
        'back office' => ['symfony', 'react', 'node.js', 'backend developer', 'frontend developer', 'full stack developer'],
        'component architecture' => ['react', 'vue.js', 'frontend developer', 'react developer', 'full stack developer'],
        'ci/cd' => ['docker', 'kubernetes', 'devops engineer'],
        'observability' => ['docker', 'kubernetes', 'devops engineer', 'site reliability engineer'],
        'test automation' => ['qa engineer', 'test automation engineer'],
        'non regression testing' => ['qa engineer', 'test automation engineer'],
        'e2e testing' => ['qa engineer', 'test automation engineer', 'frontend developer'],
        'networking' => ['devops engineer'],
        'kubernetes' => ['docker', 'devops engineer'],
    ];

    public function __construct(
        private readonly CandidateSkillInferenceService $candidateSkillInferenceService,
        private readonly SemanticMatchingService $semanticMatchingService,
    ) {
    }

    /**
     * @param list<CandidateProfile>                                                        $candidates
     * @param array<string, array{score: ?float, percentage: ?float, dimension: ?int}>|null $rawSemanticScores
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float, dimension: ?int, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string}>}
     */
    public function scoreCandidates(JobOffer $offer, array $candidates, ?array $rawSemanticScores = null): array
    {
        $rawScores = $rawSemanticScores ?? $this->semanticMatchingService->scoreCandidates($offer, $candidates)['scores'];
        $scores = [];
        $normalizedOfferHardSkills = array_map([$this, 'normalize'], $offer->requiredHardSkills);
        $normalizedOfferSoftSkills = array_map([$this, 'normalize'], $offer->desiredSoftSkills);
        $inferences = $this->candidateSkillInferenceService->inferManyFromTexts(array_map(
            static fn (CandidateProfile $candidate): string => $candidate->rawCv,
            $candidates,
        ));

        foreach ($candidates as $index => $candidate) {
            $inference = $inferences[$index] ?? [
                'available' => false,
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => [],
                'inferredTechnicalSkills' => [],
                'confidence' => [],
                'enrichedText' => $candidate->rawCv,
                'normalizedText' => $candidate->rawCv,
            ];
            $semanticScore = $rawScores[$candidate->id] ?? [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
            ];

            $bonus = $this->computeInferenceBonus(
                $candidate,
                $inference,
                $normalizedOfferHardSkills,
                $normalizedOfferSoftSkills,
            );

            $finalScore = null;
            $finalPercentage = null;
            if (is_float($semanticScore['score']) || is_int($semanticScore['score'])) {
                $finalScore = round(min(1.0, max(0.0, (float) $semanticScore['score'] + $bonus)), 4);
                $finalPercentage = round($finalScore * 100, 1);
            }

            $scores[$candidate->id] = [
                'score' => $finalScore,
                'percentage' => $finalPercentage,
                'dimension' => $semanticScore['dimension'],
                'inferredSoftSkills' => $inference['inferredSoftSkills'],
                'inferredTransferableSkills' => $inference['inferredTransferableSkills'],
                'inferredTechnicalSkills' => $inference['inferredTechnicalSkills'],
                'confidence' => $inference['confidence'],
                'enrichedText' => $inference['enrichedText'],
            ];
        }

        return [
            'available' => true,
            'scores' => $scores,
        ];
    }

    /**
     * @param array{inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>} $inference
     * @param list<string>                                                                                                                                                             $normalizedOfferHardSkills
     * @param list<string>                                                                                                                                                             $normalizedOfferSoftSkills
     */
    private function computeInferenceBonus(CandidateProfile $candidate, array $inference, array $normalizedOfferHardSkills, array $normalizedOfferSoftSkills): float
    {
        $normalizedCandidateHardSkills = array_map([$this, 'normalize'], $candidate->hardSkills);
        $normalizedCandidateSoftSkills = array_map([$this, 'normalize'], $candidate->softSkills);

        $technicalBonus = 0.0;
        foreach ($inference['inferredTechnicalSkills'] as $technicalSkill) {
            $normalizedSkill = $this->normalize($technicalSkill['skill']);

            // Skip skills the candidate already lists explicitly.
            if (in_array($normalizedSkill, $normalizedCandidateHardSkills, true)) {
                continue;
            }

            // Check direct match or semantic proximity with offer requirements.
            $matchWeight = $this->skillMatchWeight($normalizedSkill, $normalizedOfferHardSkills);
            if ($matchWeight <= 0.0) {
                continue;
            }

            $levelWeight = self::LEVEL_WEIGHTS[$technicalSkill['level']] ?? 0.25;
            $technicalBonus += 0.08 * $levelWeight * $technicalSkill['confidence'] * $matchWeight;
        }

        $softBonus = 0.0;
        foreach ($inference['inferredSoftSkills'] as $skill) {
            $normalizedSkill = $this->normalize($skill);
            if (!in_array($normalizedSkill, $normalizedOfferSoftSkills, true)) {
                continue;
            }

            if (in_array($normalizedSkill, $normalizedCandidateSoftSkills, true)) {
                continue;
            }

            $softBonus += 0.03 * ($inference['confidence'][$skill] ?? 0.5);
        }

        return round(min(0.15, $technicalBonus + $softBonus), 4);
    }

    /**
     * Returns 1.0 for a direct match, 0.6 for a proximity match, 0.0 otherwise.
     *
     * @param list<string> $offerSkills
     */
    private function skillMatchWeight(string $inferredSkill, array $offerSkills): float
    {
        if (in_array($inferredSkill, $offerSkills, true)) {
            return 1.0;
        }

        foreach (self::SKILL_PROXIMITY[$inferredSkill] ?? [] as $related) {
            if (in_array($this->normalize($related), $offerSkills, true)) {
                return 0.6;
            }
        }

        return 0.0;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
