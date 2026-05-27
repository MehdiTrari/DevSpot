<?php

declare(strict_types=1);

namespace App\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;

final class EnrichedMatchingService
{
    private const INCOMPATIBLE_FAMILY_SCORE_CAP = 0.74;

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

    /**
     * Broad role-family guardrails. They do not replace CamemBERT; they prevent
     * the enriched bonus from promoting semantically close but off-family profiles.
     */
    private const FAMILY_KEYWORDS = [
        'backend' => ['backend', 'back-end', 'php', 'symfony', 'api platform', 'node.js', 'nodejs', 'api development'],
        'frontend' => ['frontend', 'front-end', 'react', 'vue.js', 'vuejs', 'javascript', 'typescript', 'html', 'css', 'tailwind'],
        'fullstack' => ['full stack', 'fullstack', 'full-stack'],
        'devops' => ['devops', 'platform engineer', 'docker', 'kubernetes', 'linux', 'ci/cd', 'gitlab ci', 'redis', 'observability'],
        'qa' => ['qa', 'quality', 'qualite', 'test automation', 'testing', 'postman'],
        'mobile' => ['mobile', 'android', 'kotlin'],
        'data' => ['data engineer', 'python', 'airflow', 'etl', 'pandas'],
    ];

    private const DEVELOPMENT_FAMILIES = ['backend', 'frontend', 'fullstack'];

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
                $offer,
                $candidate,
                $inference,
                $normalizedOfferHardSkills,
                $normalizedOfferSoftSkills,
            );

            $finalScore = null;
            $finalPercentage = null;
            if (is_float($semanticScore['score']) || is_int($semanticScore['score'])) {
                $finalScore = round(min(1.0, max(0.0, (float) $semanticScore['score'] + $bonus)), 4);
                $finalScore = $this->applyFamilyGuardrail($offer, $candidate, $finalScore);
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
    private function computeInferenceBonus(JobOffer $offer, CandidateProfile $candidate, array $inference, array $normalizedOfferHardSkills, array $normalizedOfferSoftSkills): float
    {
        $normalizedCandidateHardSkills = array_map([$this, 'normalize'], $candidate->hardSkills);
        $normalizedCandidateSoftSkills = array_map([$this, 'normalize'], $candidate->softSkills);

        if (!$this->hasCompatiblePrimaryFamily($offer, $candidate)) {
            return 0.0;
        }

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

    private function applyFamilyGuardrail(JobOffer $offer, CandidateProfile $candidate, float $score): float
    {
        if ($this->hasCompatiblePrimaryFamily($offer, $candidate)) {
            return $score;
        }

        return round(min($score, self::INCOMPATIBLE_FAMILY_SCORE_CAP), 4);
    }

    private function hasCompatiblePrimaryFamily(JobOffer $offer, CandidateProfile $candidate): bool
    {
        $offerFamilies = $this->primaryFamilies($this->familiesFromText(implode(' ', [
            $offer->title,
            $offer->description,
            implode(' ', $offer->requiredHardSkills),
        ])));
        if ($this->hasBlockingRoleMismatch($offerFamilies, $candidate->rawCv)) {
            return false;
        }

        $candidateFamilies = $this->primaryFamilies($this->familiesFromText(implode(' ', [
            $candidate->rawCv,
            implode(' ', $candidate->hardSkills),
        ])));

        if ([] === $offerFamilies || [] === $candidateFamilies) {
            return true;
        }

        if ([] !== array_intersect($offerFamilies, $candidateFamilies)) {
            return true;
        }

        if (in_array('fullstack', $offerFamilies, true)) {
            return [] !== array_intersect(['backend', 'frontend'], $candidateFamilies);
        }

        return false;
    }

    /**
     * Human review showed that QA/DevOps/mobile profiles can share delivery or
     * tooling vocabulary with developer offers. A headline-level mismatch is a
     * stronger signal than incidental HTML/CSS/Docker keywords.
     *
     * @param list<string> $offerPrimaryFamilies
     */
    private function hasBlockingRoleMismatch(array $offerPrimaryFamilies, string $candidateText): bool
    {
        if ([] === array_intersect(self::DEVELOPMENT_FAMILIES, $offerPrimaryFamilies)) {
            return false;
        }

        $headline = $this->candidateHeadline($candidateText);
        if ('' === $headline) {
            $headline = $candidateText;
        }

        $normalizedHeadline = $this->normalize($headline);
        $hasDeveloperHeadline = str_contains($normalizedHeadline, 'developpeur')
            || str_contains($normalizedHeadline, 'developer')
            || str_contains($normalizedHeadline, 'full stack')
            || str_contains($normalizedHeadline, 'frontend')
            || str_contains($normalizedHeadline, 'backend')
            || str_contains($normalizedHeadline, 'php')
            || str_contains($normalizedHeadline, 'symfony')
            || str_contains($normalizedHeadline, 'react')
            || str_contains($normalizedHeadline, 'node');

        if ($hasDeveloperHeadline) {
            return false;
        }

        $hasBlockingHeadline = str_contains($normalizedHeadline, 'qa')
            || str_contains($normalizedHeadline, 'qualite')
            || str_contains($normalizedHeadline, 'quality')
            || str_contains($normalizedHeadline, 'devops')
            || str_contains($normalizedHeadline, 'platform engineer')
            || str_contains($normalizedHeadline, 'mobile')
            || str_contains($normalizedHeadline, 'android')
            || str_contains($normalizedHeadline, 'data engineer');

        if (!$hasBlockingHeadline && $headline === $candidateText) {
            return [] !== array_intersect(
                ['qa', 'devops', 'mobile', 'data'],
                $this->primaryFamilies($this->familiesFromText($candidateText)),
            );
        }

        return $hasBlockingHeadline;
    }

    private function candidateHeadline(string $candidateText): string
    {
        if (1 === preg_match('/^Headline:\s*(.+)$/mi', $candidateText, $matches)) {
            return trim($matches[1]);
        }

        $firstLine = strtok($candidateText, "\n");

        return is_string($firstLine) ? trim($firstLine) : '';
    }

    /**
     * @param list<string> $families
     *
     * @return list<string>
     */
    private function primaryFamilies(array $families): array
    {
        if ([] !== array_intersect(self::DEVELOPMENT_FAMILIES, $families)) {
            return array_values(array_intersect(self::DEVELOPMENT_FAMILIES, $families));
        }

        return $families;
    }

    /**
     * @return list<string>
     */
    private function familiesFromText(string $text): array
    {
        $normalizedText = $this->normalize($text);
        $families = [];

        foreach (self::FAMILY_KEYWORDS as $family => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedText, $keyword)) {
                    $families[] = $family;
                    break;
                }
            }
        }

        return array_values(array_unique($families));
    }
}
