<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeveloperProfile;
use App\Entity\JobOffer;
use App\Entity\Position;
use App\Entity\Skill;
use App\Entity\Technology;
use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer as MatchingJobOffer;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\FairnessAuditor;
use App\Matching\Service\SkillMatcher;
use App\Repository\PositionRepository;
use App\Repository\SkillRepository;
use App\Repository\TechnologyRepository;
use Symfony\Component\String\UnicodeString;

final class OfferMatchingService
{
    private const DEFAULT_SEMANTIC_RERANK_LIMIT = 50;
    private const DEFAULT_VECTOR_RETRIEVAL_LIMIT = 100;

    /** @var list<Skill>|null */
    private ?array $skills = null;

    /** @var list<Technology>|null */
    private ?array $technologies = null;

    /** @var list<Position>|null */
    private ?array $positions = null;

    public function __construct(
        private readonly SkillMatcher $skillMatcher,
        private readonly FairnessAuditor $fairnessAuditor,
        private readonly CvAnonymizer $cvAnonymizer,
        private readonly CandidateTextPreprocessor $candidateTextPreprocessor,
        private readonly CandidateProfileEmbeddingService $candidateProfileEmbeddingService,
        private readonly SemanticMatchingService $semanticMatchingService,
        private readonly EnrichedMatchingService $enrichedMatchingService,
        private readonly SkillRepository $skillRepository,
        private readonly TechnologyRepository $technologyRepository,
        private readonly PositionRepository $positionRepository,
        private readonly int $semanticRerankLimit = self::DEFAULT_SEMANTIC_RERANK_LIMIT,
    ) {
    }

    /**
     * @param list<JobOffer>         $offers
     * @param list<DeveloperProfile> $developers
     *
     * @return list<array{
     *     offer: array{id: int|null, title: string, location: ?string, locationType: ?string, contractType: ?string, experienceLevel: ?int},
     *     extractedRequirements: array{hardSkills: list<string>, softSkills: list<string>},
     *     fairness: array<string, float|int|string>,
     *     semantic: array{available: bool, fairness: array<string, float|int|string>},
     *     enriched: array{available: bool, fairness: array<string, float|int|string>},
     *     matches: list<array{
     *         developerId: int|null,
     *         slug: ?string,
     *         fullName: string,
     *         headline: string,
     *         yearsExperience: int,
     *         percentage: float,
     *         score: float,
     *         semanticPercentage: ?float,
     *         semanticScore: ?float,
     *         semanticDimension: ?int,
     *         semanticEnrichedPercentage: ?float,
     *         semanticEnrichedScore: ?float,
     *         semanticEnrichedDimension: ?int,
     *         scoreBreakdown: array<string, float>,
     *         matchedHardSkills: list<string>,
     *         matchedSoftSkills: list<string>,
     *         inferredSoftSkills: list<string>,
     *         inferredTransferableSkills: list<string>,
     *         anonymizedCv: string
     *     }>
     * }>
     */
    public function buildOfferMatches(array $offers, array $developers): array
    {
        $payload = [];

        foreach ($offers as $offer) {
            $payload[] = $this->buildSingleOfferMatch($offer, $developers);
        }

        return $payload;
    }

    /**
     * @param list<DeveloperProfile> $developers
     *
     * @return array{
     *     offer: array{id: int|null, title: string, location: ?string, locationType: ?string, contractType: ?string, experienceLevel: ?int},
     *     extractedRequirements: array{hardSkills: list<string>, softSkills: list<string>},
     *     fairness: array<string, float|int|string>,
     *     semantic: array{available: bool, fairness: array<string, float|int|string>},
     *     enriched: array{available: bool, fairness: array<string, float|int|string>},
     *     matches: list<array<string, mixed>>
     * }
     */
    public function buildSingleOfferMatch(JobOffer $offer, array $developers): array
    {
        $matchingOffer = $this->toMatchingOffer($offer);
        $candidates = array_map(fn (DeveloperProfile $developer): CandidateProfile => $this->toCandidateProfile($developer), $developers);
        $developerById = [];
        foreach ($developers as $developer) {
            $developerById[(string) $developer->getId()] = $developer;
        }

        $results = $this->skillMatcher->rankCandidates($matchingOffer, $candidates);
        $retrievalCandidateIds = $this->vectorRetrievalCandidateIds($matchingOffer, $developers);
        $semanticRerankCandidates = $this->semanticRerankCandidates($results, $retrievalCandidateIds);
        $semanticRerankDevelopers = $this->semanticRerankDevelopers($semanticRerankCandidates, $developerById);
        $semanticMatches = $this->semanticScoresForAllCandidates($matchingOffer, $candidates, $semanticRerankCandidates, $semanticRerankDevelopers);
        $enrichedMatches = $this->enrichedScoresForAllCandidates($matchingOffer, $candidates, $semanticRerankCandidates, $semanticMatches['scores']);

        $semanticFairness = $this->fairnessAuditor->auditCandidateScores(array_map(
            static fn ($result): array => [
                'yearsOfExperience' => $result->candidate->yearsOfExperience,
                'score' => $semanticMatches['scores'][$result->candidate->id]['score'] ?? null,
            ],
            $results,
        ));
        $enrichedFairness = $this->fairnessAuditor->auditCandidateScores(array_map(
            static fn ($result): array => [
                'yearsOfExperience' => $result->candidate->yearsOfExperience,
                'score' => $enrichedMatches['scores'][$result->candidate->id]['score'] ?? null,
            ],
            $results,
        ));

        $matches = array_map(function ($result) use ($matchingOffer, $developerById, $semanticMatches, $enrichedMatches): array {
            $developer = $developerById[$result->candidate->id] ?? null;
            $candidateHardSkills = array_map('mb_strtolower', $result->candidate->hardSkills);
            $candidateSoftSkills = array_map('mb_strtolower', $result->candidate->softSkills);
            $semanticScore = $semanticMatches['scores'][$result->candidate->id] ?? [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
            ];
            $enrichedScore = $enrichedMatches['scores'][$result->candidate->id] ?? [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => [],
                'inferredTechnicalSkills' => [],
            ];

            return [
                'developerId' => $developer?->getId(),
                'slug' => $developer?->getSlug(),
                'fullName' => trim(sprintf('%s %s', (string) $developer?->getFirstName(), (string) $developer?->getLastName())),
                'headline' => (string) $developer?->getHeadline(),
                'yearsExperience' => $result->candidate->yearsOfExperience,
                'updatedAt' => $developer?->getUpdatedAt()?->format('Y-m-d\TH:i:sP'),
                'percentage' => round($result->score * 100, 1),
                'score' => $result->score,
                'semanticPercentage' => $semanticScore['percentage'],
                'semanticScore' => $semanticScore['score'],
                'semanticDimension' => $semanticScore['dimension'],
                'semanticEnrichedPercentage' => $enrichedScore['percentage'],
                'semanticEnrichedScore' => $enrichedScore['score'],
                'semanticEnrichedDimension' => $enrichedScore['dimension'],
                'scoreBreakdown' => $result->scoreBreakdown,
                'matchedHardSkills' => array_values(array_map(
                    static fn (string $skill): string => $skill,
                    array_intersect(array_map('mb_strtolower', $matchingOffer->requiredHardSkills), $candidateHardSkills),
                )),
                'matchedSoftSkills' => array_values(array_map(
                    static fn (string $skill): string => $skill,
                    array_intersect(array_map('mb_strtolower', $matchingOffer->desiredSoftSkills), $candidateSoftSkills),
                )),
                'inferredSoftSkills' => $enrichedScore['inferredSoftSkills'],
                'inferredTransferableSkills' => $enrichedScore['inferredTransferableSkills'],
                'inferredTechnicalSkills' => $enrichedScore['inferredTechnicalSkills'],
                'anonymizedCv' => $result->candidate->rawCv,
            ];
        }, $results);
        $this->sortMatchesByEnrichedScore($matches);

        return [
            'offer' => [
                'id' => $offer->getId(),
                'title' => (string) $offer->getTitle(),
                'location' => $offer->getLocation(),
                'locationType' => $offer->getLocationType()?->value,
                'contractType' => $offer->getContractType()?->value,
                'experienceLevel' => $offer->getExperienceLevel(),
            ],
            'extractedRequirements' => [
                'hardSkills' => $matchingOffer->requiredHardSkills,
                'softSkills' => $matchingOffer->desiredSoftSkills,
            ],
            'fairness' => $this->fairnessAuditor->auditJuniorBias($results),
            'semantic' => [
                'available' => $semanticMatches['available'],
                'fairness' => $semanticFairness,
            ],
            'enriched' => [
                'available' => $enrichedMatches['available'],
                'fairness' => $enrichedFairness,
            ],
            'matches' => $matches,
        ];
    }

    private function toMatchingOffer(JobOffer $offer): MatchingJobOffer
    {
        $text = trim(sprintf('%s %s', (string) $offer->getTitle(), (string) $offer->getDescription()));

        return new MatchingJobOffer(
            (string) ($offer->getId() ?? spl_object_id($offer)),
            (string) $offer->getTitle(),
            $this->extractHardSkills($text),
            $this->extractSoftSkills($text),
            (string) $offer->getDescription(),
        );
    }

    /**
     * @param list<array{semanticEnrichedScore: ?float, semanticScore: ?float, score: float, fullName: string}> $matches
     */
    private function sortMatchesByEnrichedScore(array &$matches): void
    {
        usort($matches, static function (array $left, array $right): int {
            $leftEnriched = $left['semanticEnrichedScore'] ?? -1.0;
            $rightEnriched = $right['semanticEnrichedScore'] ?? -1.0;
            if ($leftEnriched !== $rightEnriched) {
                return $rightEnriched <=> $leftEnriched;
            }

            $leftSemantic = $left['semanticScore'] ?? -1.0;
            $rightSemantic = $right['semanticScore'] ?? -1.0;
            if ($leftSemantic !== $rightSemantic) {
                return $rightSemantic <=> $leftSemantic;
            }

            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['fullName'] <=> $right['fullName'];
        });
    }

    /**
     * @param list<\App\Matching\Model\MatchResult> $results
     * @param list<string>                          $retrievalCandidateIds
     *
     * @return list<CandidateProfile>
     */
    private function semanticRerankCandidates(array $results, array $retrievalCandidateIds = []): array
    {
        if ([] === $results) {
            return [];
        }

        $limit = $this->semanticRerankLimit > 0 ? $this->semanticRerankLimit : self::DEFAULT_SEMANTIC_RERANK_LIMIT;

        if ([] === $retrievalCandidateIds) {
            return array_map(
                static fn ($result): CandidateProfile => $result->candidate,
                array_slice($results, 0, min($limit, count($results))),
            );
        }

        $resultsById = [];
        foreach ($results as $result) {
            $resultsById[$result->candidate->id] = $result;
        }

        $selected = [];
        foreach ($retrievalCandidateIds as $candidateId) {
            if (isset($resultsById[$candidateId])) {
                $selected[] = $resultsById[$candidateId]->candidate;
            }

            if (count($selected) >= $limit) {
                break;
            }
        }

        if (count($selected) < $limit) {
            $selectedIds = array_fill_keys(array_map(static fn (CandidateProfile $candidate): string => $candidate->id, $selected), true);
            foreach ($results as $result) {
                if (isset($selectedIds[$result->candidate->id])) {
                    continue;
                }

                $selected[] = $result->candidate;
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * @param list<CandidateProfile>                 $rerankCandidates
     * @param array<string, DeveloperProfile> $developerById
     *
     * @return list<DeveloperProfile>
     */
    private function semanticRerankDevelopers(array $rerankCandidates, array $developerById): array
    {
        $developers = [];

        foreach ($rerankCandidates as $candidate) {
            $developer = $developerById[$candidate->id] ?? null;
            if ($developer instanceof DeveloperProfile) {
                $developers[] = $developer;
            }
        }

        return $developers;
    }

    /**
     * @param list<DeveloperProfile> $developers
     *
     * @return list<string>
     */
    private function vectorRetrievalCandidateIds(MatchingJobOffer $offer, array $developers): array
    {
        if ([] === $developers) {
            return [];
        }

        $storedEmbeddings = $this->candidateProfileEmbeddingService->storedEmbeddingsForProfiles($developers);
        if ([] === $storedEmbeddings) {
            return [];
        }

        $retrievalMatches = $this->semanticMatchingService->scoreEmbeddingMap(
            trim(sprintf('%s %s', $offer->title, $offer->description)),
            $storedEmbeddings,
        );

        if (!$retrievalMatches['available'] || [] === $retrievalMatches['scores']) {
            return [];
        }

        $scores = $retrievalMatches['scores'];
        uasort($scores, static function (array $left, array $right): int {
            $leftScore = $left['score'] ?? -1.0;
            $rightScore = $right['score'] ?? -1.0;

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            return ($left['dimension'] ?? 0) <=> ($right['dimension'] ?? 0);
        });

        $limit = $this->semanticRerankLimit > 0
            ? max($this->semanticRerankLimit, self::DEFAULT_VECTOR_RETRIEVAL_LIMIT)
            : self::DEFAULT_VECTOR_RETRIEVAL_LIMIT;

        return array_slice(array_keys($scores), 0, min($limit, count($scores)));
    }

    /**
     * @param list<CandidateProfile> $allCandidates
     * @param list<CandidateProfile> $rerankCandidates
     * @param list<DeveloperProfile> $rerankDevelopers
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float, dimension: ?int}>}
     */
    private function semanticScoresForAllCandidates(MatchingJobOffer $offer, array $allCandidates, array $rerankCandidates, array $rerankDevelopers): array
    {
        $scores = [];
        foreach ($allCandidates as $candidate) {
            $scores[$candidate->id] = [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
            ];
        }

        if ([] === $rerankCandidates) {
            return [
                'available' => false,
                'scores' => $scores,
            ];
        }

        $this->candidateProfileEmbeddingService->refreshEmbeddings($rerankDevelopers);
        $storedEmbeddings = $this->candidateProfileEmbeddingService->storedEmbeddingsForProfiles($rerankDevelopers);

        $semanticMatches = [
            'available' => false,
            'scores' => [],
        ];
        if ([] !== $storedEmbeddings) {
            $semanticMatches = $this->semanticMatchingService->scoreEmbeddingMap(
                trim(sprintf('%s %s', $offer->title, $offer->description)),
                $storedEmbeddings,
            );
        }

        $fallbackCandidates = array_values(array_filter(
            $rerankCandidates,
            static fn (CandidateProfile $candidate): bool => !array_key_exists($candidate->id, $storedEmbeddings),
        ));
        $fallbackMatches = [
            'available' => false,
            'scores' => [],
        ];
        if ([] !== $fallbackCandidates) {
            $fallbackMatches = $this->semanticMatchingService->scoreCandidates($offer, $fallbackCandidates);
        }

        foreach ($semanticMatches['scores'] as $candidateId => $candidateScore) {
            $scores[$candidateId] = $candidateScore;
        }
        foreach ($fallbackMatches['scores'] as $candidateId => $candidateScore) {
            $scores[$candidateId] = $candidateScore;
        }

        return [
            'available' => $semanticMatches['available'] || $fallbackMatches['available'],
            'scores' => $scores,
        ];
    }

    /**
     * @param list<CandidateProfile> $allCandidates
     * @param list<CandidateProfile> $rerankCandidates
     * @param array<string, array{score: ?float, percentage: ?float, dimension: ?int}> $semanticScores
     *
     * @return array{available: bool, scores: array<string, array{score: ?float, percentage: ?float, dimension: ?int, inferredSoftSkills: list<string>, inferredTransferableSkills: list<string>, inferredTechnicalSkills: list<array{skill: string, level: string, confidence: float}>, confidence: array<string, float>, enrichedText: string}>}
     */
    private function enrichedScoresForAllCandidates(MatchingJobOffer $offer, array $allCandidates, array $rerankCandidates, array $semanticScores): array
    {
        $scores = [];
        foreach ($allCandidates as $candidate) {
            $scores[$candidate->id] = [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
                'inferredSoftSkills' => [],
                'inferredTransferableSkills' => [],
                'inferredTechnicalSkills' => [],
                'confidence' => [],
                'enrichedText' => $candidate->rawCv,
            ];
        }

        if ([] === $rerankCandidates) {
            return [
                'available' => false,
                'scores' => $scores,
            ];
        }

        $semanticScoresForRerank = [];
        foreach ($rerankCandidates as $candidate) {
            $semanticScoresForRerank[$candidate->id] = $semanticScores[$candidate->id] ?? [
                'score' => null,
                'percentage' => null,
                'dimension' => null,
            ];
        }

        $enrichedMatches = $this->enrichedMatchingService->scoreCandidates($offer, $rerankCandidates, $semanticScoresForRerank);

        foreach ($enrichedMatches['scores'] as $candidateId => $candidateScore) {
            $scores[$candidateId] = $candidateScore;
        }

        return [
            'available' => $enrichedMatches['available'],
            'scores' => $scores,
        ];
    }

    private function toCandidateProfile(DeveloperProfile $developer): CandidateProfile
    {
        $hardSkills = [];
        $softSkills = [];

        foreach ($developer->getProfileSkills() as $profileSkill) {
            $skill = $profileSkill->getSkill();
            if (!$skill instanceof Skill || null === $skill->getName()) {
                continue;
            }

            if ('Soft Skills' === $skill->getCategory()) {
                $softSkills[] = $skill->getName();
                continue;
            }

            $hardSkills[] = $skill->getName();
        }

        foreach ($developer->getExperiences() as $experience) {
            foreach ($experience->getTechnologies() as $technology) {
                if (null !== $technology->getName()) {
                    $hardSkills[] = $technology->getName();
                }
            }
        }

        foreach ($developer->getDesiredPositions() as $position) {
            if (null !== $position->getName()) {
                $hardSkills[] = $position->getName();
            }
        }

        $cv = $this->candidateTextPreprocessor->buildCandidateText($developer);
        $hardSkills = [...$hardSkills, ...$this->extractHardSkills($cv)];
        $softSkills = [...$softSkills, ...$this->extractSoftSkills($cv)];
        $termsToMask = array_values(array_filter([
            (string) $developer->getFirstName(),
            (string) $developer->getLastName(),
            (string) $developer->getSlug(),
            (string) $developer->getGithubUrl(),
            (string) $developer->getLinkedinUrl(),
            (string) $developer->getPortfolioUrl(),
        ], static fn (string $value): bool => '' !== trim($value)));

        return new CandidateProfile(
            (string) ($developer->getId() ?? spl_object_id($developer)),
            (int) ($developer->getYearsExperience() ?? 0),
            $this->uniqueValues($hardSkills),
            $this->uniqueValues($softSkills),
            $this->cvAnonymizer->anonymize($cv, $termsToMask),
        );
    }

    /**
     * @return list<string>
     */
    private function extractHardSkills(string $text): array
    {
        $matches = [];

        foreach ($this->allSkills() as $skill) {
            if ('Soft Skills' === $skill->getCategory()) {
                continue;
            }

            $name = (string) $skill->getName();
            if ('' !== $name && $this->containsTerm($text, $name)) {
                $matches[] = $name;
            }
        }

        foreach ($this->allTechnologies() as $technology) {
            $name = (string) $technology->getName();
            if ('' !== $name && $this->containsTerm($text, $name)) {
                $matches[] = $name;
            }
        }

        foreach ($this->allPositions() as $position) {
            $name = (string) $position->getName();
            if ('' !== $name && $this->containsTerm($text, $name)) {
                $matches[] = $name;
            }
        }

        return $this->uniqueValues($matches);
    }

    /**
     * @return list<string>
     */
    private function extractSoftSkills(string $text): array
    {
        $matches = [];

        foreach ($this->allSkills() as $skill) {
            if ('Soft Skills' !== $skill->getCategory()) {
                continue;
            }

            $name = (string) $skill->getName();
            if ('' !== $name && $this->containsTerm($text, $name)) {
                $matches[] = $name;
            }
        }

        return $this->uniqueValues($matches);
    }

    private function containsTerm(string $haystack, string $needle): bool
    {
        $normalizedHaystack = $this->normalize($haystack);
        $normalizedNeedle = $this->normalize($needle);

        return '' !== $normalizedNeedle && str_contains($normalizedHaystack, $normalizedNeedle);
    }

    private function normalize(string $value): string
    {
        return (new UnicodeString($value))
            ->ascii()
            ->lower()
            ->collapseWhitespace()
            ->toString();
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
            if ('' === $trimmed) {
                continue;
            }

            $key = mb_strtolower($trimmed);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $trimmed;
        }

        return $unique;
    }

    /**
     * @return list<Skill>
     */
    private function allSkills(): array
    {
        return $this->skills ??= $this->skillRepository->findAll();
    }

    /**
     * @return list<Technology>
     */
    private function allTechnologies(): array
    {
        return $this->technologies ??= $this->technologyRepository->findAll();
    }

    /**
     * @return list<Position>
     */
    private function allPositions(): array
    {
        return $this->positions ??= $this->positionRepository->findAll();
    }
}
