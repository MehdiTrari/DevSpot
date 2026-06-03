<?php

declare(strict_types=1);

namespace App\Service;

use App\Matching\Model\JobOffer;
use App\Matching\Model\MatchResult;
use Symfony\Component\String\UnicodeString;

final class RerankerMatchingService
{
    private const KEYWORDS = [
        'php', 'symfony', 'react', 'vue.js', 'node.js', 'javascript', 'typescript',
        'html', 'css', 'tailwind css', 'sql', 'mysql', 'postgresql', 'docker',
        'kubernetes', 'redis', 'linux', 'ci/cd', 'observability', 'test automation',
        'qa', 'api development', 'postman', 'figma', 'android', 'kotlin', 'python',
        'airflow', 'full stack', 'frontend', 'backend', 'devops', 'mobile', 'data',
    ];

    private const PROXIMITY_SKILLS = [
        'javascript' => ['react', 'vue.js', 'node.js', 'typescript', 'frontend'],
        'typescript' => ['react', 'vue.js', 'node.js', 'frontend', 'full stack'],
        'react' => ['javascript', 'typescript', 'frontend', 'full stack'],
        'vue.js' => ['javascript', 'typescript', 'frontend', 'node.js'],
        'node.js' => ['javascript', 'typescript', 'backend', 'api development'],
        'php' => ['symfony', 'backend', 'api development'],
        'symfony' => ['php', 'backend', 'api development'],
        'sql' => ['mysql', 'postgresql', 'backend', 'data'],
        'postgresql' => ['sql', 'backend', 'data'],
        'mysql' => ['sql', 'backend'],
        'docker' => ['devops', 'kubernetes', 'ci/cd'],
        'kubernetes' => ['devops', 'docker'],
        'ci/cd' => ['devops', 'docker'],
        'observability' => ['devops', 'kubernetes'],
        'qa' => ['test automation'],
        'test automation' => ['qa'],
        'python' => ['data', 'airflow', 'sql'],
        'airflow' => ['data', 'python'],
        'android' => ['kotlin', 'mobile'],
        'kotlin' => ['android', 'mobile'],
    ];

    private const ROLE_FAMILY_KEYWORDS = [
        'backend' => ['php', 'symfony', 'sql', 'mysql', 'postgresql', 'api development', 'backend'],
        'frontend' => ['react', 'vue.js', 'javascript', 'typescript', 'html', 'css', 'tailwind css', 'frontend'],
        'fullstack' => ['full stack', 'backend', 'frontend'],
        'node' => ['node.js', 'javascript', 'typescript'],
        'devops' => ['devops', 'docker', 'kubernetes', 'redis', 'linux', 'ci/cd', 'observability'],
        'qa' => ['qa', 'test automation', 'postman'],
        'mobile' => ['mobile', 'android', 'kotlin'],
        'data' => ['data', 'python', 'airflow'],
    ];

    private const FAMILY_REQUIRED_KEYWORDS = [
        'backend' => ['php', 'symfony', 'sql', 'mysql', 'postgresql', 'api development'],
        'frontend' => ['react', 'vue.js', 'javascript', 'typescript', 'html', 'css', 'tailwind css'],
        'node' => ['node.js', 'javascript', 'typescript', 'api development', 'postgresql'],
        'devops' => ['docker', 'kubernetes', 'redis', 'linux', 'ci/cd', 'observability'],
        'qa' => ['qa', 'test automation', 'postman'],
        'mobile' => ['android', 'kotlin', 'mobile'],
        'data' => ['python', 'airflow', 'sql', 'postgresql'],
    ];

    private const DEVELOPMENT_FAMILIES = ['backend', 'frontend', 'fullstack', 'node'];

    private const ROLE_TEMPLATE_RULES = [
        ['contains' => ['full stack'], 'groups' => [['react'], ['symfony'], ['php', 'sql', 'postgresql', 'mysql', 'api']]],
        ['contains' => ['devops engineer'], 'anyCount' => ['devops', 'docker', 'kubernetes', 'ci cd', 'observability', 'linux', 'sre', 'platform'], 'minimumHits' => 3],
        ['contains' => ['android'], 'groups' => [['android'], ['kotlin']]],
        ['contains' => ['backend symfony'], 'groups' => [['symfony'], ['php'], ['sql', 'postgresql', 'mysql', 'api']]],
        ['contains' => ['qa engineer'], 'groups' => [['qa', 'qualite', 'quality'], ['automation', 'automatisation', 'testing', 'test']]],
        ['contains' => ['integrateur frontend'], 'groups' => [['integrateur', 'frontend', 'html'], ['css', 'tailwind'], ['accessible', 'accessibilite', 'design system', 'ui']]],
        ['equals' => 'developpeur php', 'groups' => [['php'], ['symfony', 'mysql', 'sql', 'postgresql', 'api']]],
        ['contains' => ['frontend developer react'], 'groups' => [['react'], ['typescript', 'javascript'], ['css', 'html', 'tailwind']]],
        ['contains' => ['data engineer'], 'groups' => [['python'], ['sql', 'postgresql', 'airflow', 'etl']]],
        ['contains' => ['node.js'], 'groups' => [['node'], ['typescript', 'javascript'], ['api', 'postgresql', 'sql']]],
        ['contains' => ['platform engineer'], 'groups' => [['platform', 'sre', 'cloud'], ['docker', 'kubernetes', 'observability', 'ci cd', 'linux']]],
        ['contains' => ['analytics engineer'], 'groups' => [['analytics', 'dbt'], ['sql', 'python', 'data']]],
        ['contains' => ['appsec'], 'groups' => [['appsec', 'security', 'securite', 'cyber'], ['php', 'react', 'web', 'api', 'application']], 'juniorPreferred' => true],
        ['contains' => ['vue'], 'groups' => [['vue'], ['node'], ['javascript', 'typescript']]],
    ];

    /** @var array<string, string> */
    private array $normalizationCache = [];

    public function __construct(
        private readonly AiMatchingClientInterface $aiMatchingClient,
    ) {
    }

    /**
     * @param list<MatchResult> $baselineResults
     * @param array<string, array{score: ?float, percentage: ?float, dimension: ?int}> $semanticScores
     * @param array<string, array{score: ?float, percentage: ?float, dimension: ?int}> $enrichedScores
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float}>}
     */
    public function scoreCandidates(JobOffer $offer, array $baselineResults, array $semanticScores, array $enrichedScores): array
    {
        $scores = [];
        $items = [];

        foreach ($baselineResults as $result) {
            $candidateId = $result->candidate->id;
            $scores[$candidateId] = [
                'score' => null,
                'percentage' => null,
            ];

            $semanticScore = $semanticScores[$candidateId]['score'] ?? null;
            $enrichedScore = $enrichedScores[$candidateId]['score'] ?? null;
            if (!is_numeric($semanticScore) || !is_numeric($enrichedScore)) {
                continue;
            }

            $items[] = [
                'candidateId' => $candidateId,
                'features' => $this->featureVector($offer, $result, (float) $semanticScore, (float) $enrichedScore),
            ];
        }

        $reranked = $this->aiMatchingClient->rerank($items);
        if (null === $reranked || [] === $items) {
            return [
                'available' => false,
                'scores' => $scores,
            ];
        }

        foreach ($reranked as $candidateId => $score) {
            if (!isset($scores[$candidateId])) {
                continue;
            }

            $boundedScore = round(max(0.0, min(1.0, $score)), 6);
            $scores[$candidateId] = [
                'score' => $boundedScore,
                'percentage' => round($boundedScore * 100, 1),
            ];
        }

        return [
            'available' => true,
            'scores' => $scores,
        ];
    }

    /**
     * @return list<float>
     */
    private function featureVector(JobOffer $offer, MatchResult $result, float $semanticScore, float $enrichedScore): array
    {
        $candidate = $result->candidate;
        $offerText = trim(implode(' ', [$offer->title, $offer->description, implode(' ', $offer->requiredHardSkills)]));
        $candidateText = trim(implode(' ', [$candidate->rawCv, implode(' ', $candidate->hardSkills)]));
        $offerKeywords = $this->keywordsFromText($offerText);
        $candidateKeywords = $this->keywordsFromText($candidateText);
        $offerFamilies = $this->roleFamilies($offerKeywords, $offerText);
        $candidateFamilies = $this->roleFamilies($candidateKeywords, $candidateText);
        $keywordOverlap = count(array_intersect($offerKeywords, $candidateKeywords));
        $familyOverlap = count(array_intersect($offerFamilies, $candidateFamilies));
        [$backendOverlap, $frontendOverlap] = $this->fullstackComponentOverlaps($offerKeywords, $candidateKeywords);
        [$titleAlignmentRatio, $exactTitleMatch] = $this->titleAlignmentFeatures($offer, $candidateText);
        $requiredYears = (float) $offer->requiredYears;
        $yearsExperience = (float) $candidate->yearsOfExperience;
        $experienceGap = $requiredYears - $yearsExperience;

        return [
            round($semanticScore, 6),
            round($enrichedScore, 6),
            $this->baselineCoreScore($result),
            (float) $keywordOverlap,
            round($keywordOverlap / max(1, count($offerKeywords)), 6),
            (float) $familyOverlap,
            (float) $this->bestFamilySpecificOverlap($offerKeywords, $candidateKeywords, $offerFamilies, $candidateFamilies),
            (float) $this->keywordProximityHits($offerKeywords, $candidateKeywords),
            $this->compatiblePrimaryFamilies($offerFamilies, $candidateFamilies) ? 1.0 : 0.0,
            $this->hasBlockingRoleMismatch($offerFamilies, $candidateText) ? 1.0 : 0.0,
            (float) $backendOverlap,
            (float) $frontendOverlap,
            in_array('fullstack', $this->primaryFamilies($offerFamilies), true) && $backendOverlap > 0 && $frontendOverlap > 0 ? 1.0 : 0.0,
            (float) $this->roleTemplateMatchGrade($offer, $candidateText, $yearsExperience),
            $titleAlignmentRatio,
            $exactTitleMatch,
            $yearsExperience,
            $requiredYears,
            $experienceGap,
            $yearsExperience >= max(0.0, $requiredYears - 1.0) ? 1.0 : 0.0,
        ];
    }

    private function baselineCoreScore(MatchResult $result): float
    {
        return round(
            min(1.0, (($result->scoreBreakdown['hard_skills'] ?? 0.0) * 0.65) + (($result->scoreBreakdown['soft_skills'] ?? 0.0) * 0.25)),
            4,
        );
    }

    /**
     * @return list<string>
     */
    private function keywordsFromText(string $text): array
    {
        $normalizedText = $this->normalize($text);
        $keywords = [];

        foreach (self::KEYWORDS as $keyword) {
            $needle = $this->normalize($keyword);
            if (str_contains($normalizedText, $needle)) {
                $keywords[] = $keyword;
            }
        }

        if (str_contains($normalizedText, 'rest') || str_contains($normalizedText, 'graphql') || str_contains($normalizedText, 'microservice')) {
            $keywords[] = 'api development';
        }
        if (str_contains($normalizedText, 'tailwind')) {
            $keywords[] = 'tailwind css';
        }
        if (str_contains($normalizedText, 'gitlab ci') || str_contains($normalizedText, 'github actions') || str_contains($normalizedText, 'pipeline')) {
            $keywords[] = 'ci/cd';
        }
        if (str_contains($normalizedText, 'postgres')) {
            $keywords[] = 'postgresql';
        }
        if (str_contains($normalizedText, 'nodejs') || str_contains($normalizedText, 'node')) {
            $keywords[] = 'node.js';
        }

        return $this->uniqueValues($keywords);
    }

    /**
     * @param list<string> $keywords
     *
     * @return list<string>
     */
    private function roleFamilies(array $keywords, string $text): array
    {
        $families = [];
        $normalizedText = $this->normalize($text);

        foreach (self::ROLE_FAMILY_KEYWORDS as $family => $requiredKeywords) {
            if ([] !== array_intersect($keywords, $requiredKeywords)) {
                $families[] = $family;
                continue;
            }

            if (str_contains($normalizedText, $family)) {
                $families[] = $family;
            }
        }

        return $this->uniqueValues($families);
    }

    /**
     * @param list<string> $families
     *
     * @return list<string>
     */
    private function primaryFamilies(array $families): array
    {
        $developmentOverlap = array_intersect($families, self::DEVELOPMENT_FAMILIES);

        return [] !== $developmentOverlap ? array_values($developmentOverlap) : $families;
    }

    /**
     * @param list<string> $offerKeywords
     * @param list<string> $candidateKeywords
     */
    private function keywordProximityHits(array $offerKeywords, array $candidateKeywords): int
    {
        $directOverlap = array_intersect($offerKeywords, $candidateKeywords);
        $hits = 0;

        foreach (array_diff($candidateKeywords, $directOverlap) as $candidateSkill) {
            if ([] !== array_intersect(self::PROXIMITY_SKILLS[$candidateSkill] ?? [], $offerKeywords)) {
                ++$hits;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $offerFamilies
     * @param list<string> $candidateFamilies
     */
    private function compatiblePrimaryFamilies(array $offerFamilies, array $candidateFamilies): bool
    {
        $offerPrimary = $this->primaryFamilies($offerFamilies);
        $candidatePrimary = $this->primaryFamilies($candidateFamilies);
        if ([] === $offerPrimary || [] === $candidatePrimary) {
            return true;
        }

        if ([] !== array_intersect($offerPrimary, $candidatePrimary)) {
            return true;
        }

        return in_array('fullstack', $offerPrimary, true) && [] !== array_intersect(['backend', 'frontend', 'node'], $candidatePrimary);
    }

    /**
     * @param list<string> $offerFamilies
     */
    private function hasBlockingRoleMismatch(array $offerFamilies, string $candidateText): bool
    {
        if ([] === array_intersect($this->primaryFamilies($offerFamilies), self::DEVELOPMENT_FAMILIES)) {
            return false;
        }

        $headline = $this->candidateHeadline($candidateText);
        $normalizedHeadline = $this->normalize('' !== $headline ? $headline : $candidateText);
        $developerTokens = ['developpeur', 'developer', 'full stack', 'frontend', 'backend', 'php', 'symfony', 'react', 'node'];
        foreach ($developerTokens as $token) {
            if (str_contains($normalizedHeadline, $token)) {
                return false;
            }
        }

        foreach (['qa', 'qualite', 'quality', 'devops', 'platform engineer', 'mobile', 'android', 'data engineer'] as $token) {
            if (str_contains($normalizedHeadline, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $offerKeywords
     * @param list<string> $candidateKeywords
     * @param list<string> $offerFamilies
     * @param list<string> $candidateFamilies
     */
    private function bestFamilySpecificOverlap(array $offerKeywords, array $candidateKeywords, array $offerFamilies, array $candidateFamilies): int
    {
        $familyOverlap = array_intersect($offerFamilies, $candidateFamilies);
        if ([] === $familyOverlap) {
            return 0;
        }

        $best = 0;
        foreach ($familyOverlap as $family) {
            $best = max($best, $this->familySpecificOverlap($offerKeywords, $candidateKeywords, $family));
        }

        return $best;
    }

    /**
     * @param list<string> $offerKeywords
     * @param list<string> $candidateKeywords
     */
    private function familySpecificOverlap(array $offerKeywords, array $candidateKeywords, string $family): int
    {
        if ('fullstack' === $family) {
            [$backendOverlap, $frontendOverlap] = $this->fullstackComponentOverlaps($offerKeywords, $candidateKeywords);

            return $backendOverlap > 0 && $frontendOverlap > 0 ? $backendOverlap + $frontendOverlap + 1 : $backendOverlap + $frontendOverlap;
        }

        $required = self::FAMILY_REQUIRED_KEYWORDS[$family] ?? [];

        return count(array_intersect(array_intersect($offerKeywords, $required), array_intersect($candidateKeywords, $required)));
    }

    /**
     * @param list<string> $offerKeywords
     * @param list<string> $candidateKeywords
     *
     * @return array{0: int, 1: int}
     */
    private function fullstackComponentOverlaps(array $offerKeywords, array $candidateKeywords): array
    {
        $backendOverlap = count(array_intersect(
            array_intersect($offerKeywords, self::FAMILY_REQUIRED_KEYWORDS['backend']),
            array_intersect($candidateKeywords, self::FAMILY_REQUIRED_KEYWORDS['backend']),
        ));
        $frontendOverlap = count(array_intersect(
            array_intersect($offerKeywords, self::FAMILY_REQUIRED_KEYWORDS['frontend']),
            array_intersect($candidateKeywords, self::FAMILY_REQUIRED_KEYWORDS['frontend']),
        ));

        return [$backendOverlap, $frontendOverlap];
    }

    private function roleTemplateMatchGrade(JobOffer $offer, string $candidateText, float $yearsExperience): int
    {
        $title = $this->normalize($offer->title);
        $normalizedCandidateText = $this->normalize($candidateText);
        $rule = null;

        foreach (self::ROLE_TEMPLATE_RULES as $candidateRule) {
            if (($candidateRule['equals'] ?? null) === $title) {
                $rule = $candidateRule;
                break;
            }

            $contains = $candidateRule['contains'] ?? [];
            if ([] !== $contains && $this->containsAll($title, $contains)) {
                $rule = $candidateRule;
                break;
            }
        }

        if (null === $rule) {
            return 0;
        }

        $grade = 0;
        if (isset($rule['anyCount']) && is_array($rule['anyCount'])) {
            $hits = 0;
            foreach ($rule['anyCount'] as $term) {
                if (str_contains($normalizedCandidateText, $this->normalize((string) $term))) {
                    ++$hits;
                }
            }

            $minimumHits = (int) ($rule['minimumHits'] ?? 3);
            $grade = $hits >= $minimumHits ? 3 : ($hits >= max(2, $minimumHits - 1) ? 2 : ($hits >= 1 ? 1 : 0));
        } else {
            $groups = is_array($rule['groups'] ?? null) ? $rule['groups'] : [];
            $matchedGroups = 0;
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                foreach ($group as $term) {
                    if (str_contains($normalizedCandidateText, $this->normalize((string) $term))) {
                        ++$matchedGroups;
                        break;
                    }
                }
            }

            $groupCount = count($groups);
            $grade = $groupCount > 0 && $matchedGroups === $groupCount ? 3 : ($groupCount > 0 && $matchedGroups >= max(2, $groupCount - 1) ? 2 : ($matchedGroups >= 1 ? 1 : 0));
        }

        if ($grade <= 0) {
            return 0;
        }

        if ($yearsExperience < max(0.0, (float) $offer->requiredYears - 2.0)) {
            $grade = min($grade, 1);
        }
        if (($rule['juniorPreferred'] ?? false) && $yearsExperience > 4.0) {
            $grade = min($grade, 2);
        }

        return $grade;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function titleAlignmentFeatures(JobOffer $offer, string $candidateText): array
    {
        $offerTitle = $this->normalize($offer->title);
        if ('' === $offerTitle) {
            return [0.0, 0.0];
        }

        $stopWords = ['developpeur', 'developpeuse', 'developer', 'engineer', 'ingenieur', 'poste', 'senior', 'junior'];
        $offerTokens = array_diff(explode(' ', $offerTitle), $stopWords);
        $roleTexts = array_filter([
            $this->candidateHeadline($candidateText),
            ...$this->candidateTargetRoles($candidateText),
        ], static fn (string $value): bool => '' !== trim($value));
        $normalizedRoleTexts = array_map([$this, 'normalize'], $roleTexts);
        $candidateTokens = [];
        foreach ($normalizedRoleTexts as $roleText) {
            $candidateTokens = [...$candidateTokens, ...array_diff(explode(' ', $roleText), $stopWords)];
        }

        if ([] === $offerTokens) {
            return [0.0, in_array($offerTitle, $normalizedRoleTexts, true) ? 1.0 : 0.0];
        }

        return [
            round(count(array_intersect($offerTokens, $candidateTokens)) / max(1, count($offerTokens)), 4),
            in_array($offerTitle, $normalizedRoleTexts, true) ? 1.0 : 0.0,
        ];
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
     * @return list<string>
     */
    private function candidateTargetRoles(string $candidateText): array
    {
        if (1 !== preg_match('/^Target roles:\s*(.+)$/mi', $candidateText, $matches)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $matches[1])), static fn (string $role): bool => '' !== $role));
    }

    private function normalize(string $value): string
    {
        if (isset($this->normalizationCache[$value])) {
            return $this->normalizationCache[$value];
        }

        $normalized = (new UnicodeString($value))
            ->ascii()
            ->lower()
            ->collapseWhitespace()
            ->toString();
        $normalized = str_replace(['nodejs', 'vuejs', 'reactjs', 'ci / cd'], ['node.js', 'vue.js', 'react', 'ci/cd'], $normalized);

        return $this->normalizationCache[$value] = $normalized;
    }

    /**
     * @param list<string> $tokens
     */
    private function containsAll(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!str_contains($haystack, $this->normalize($token))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        $seen = [];

        foreach ($values as $value) {
            $trimmed = trim($value);
            if ('' === $trimmed || isset($seen[$trimmed])) {
                continue;
            }

            $seen[$trimmed] = true;
            $unique[] = $trimmed;
        }

        return $unique;
    }
}
