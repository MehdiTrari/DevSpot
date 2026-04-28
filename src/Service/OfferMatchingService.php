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
        private readonly SemanticMatchingService $semanticMatchingService,
        private readonly EnrichedMatchingService $enrichedMatchingService,
        private readonly SkillRepository $skillRepository,
        private readonly TechnologyRepository $technologyRepository,
        private readonly PositionRepository $positionRepository,
    ) {
    }

    /**
     * @param list<JobOffer>         $offers
     * @param list<DeveloperProfile> $developers
     *
     * @return list<array{
     *     offer: array{id: int|null, title: string, location: ?string, locationType: ?string, contractType: ?string, experienceLevel: ?int},
     *     extractedRequirements: array{hardSkills: list<string>, softSkills: list<string>},
     *     fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float},
     *     semantic: array{available: bool, fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float}},
     *     enriched: array{available: bool, fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float}},
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
     *     fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float},
     *     semantic: array{available: bool, fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float}},
     *     enriched: array{available: bool, fairness: array{junior_avg_score: float, non_junior_avg_score: float, disparate_impact_ratio: float}},
     *     matches: list<array<string, mixed>>
     * }
     */
    public function buildSingleOfferMatch(JobOffer $offer, array $developers): array
    {
        $matchingOffer = $this->toMatchingOffer($offer);
        $candidates = array_map(fn (DeveloperProfile $developer): CandidateProfile => $this->toCandidateProfile($developer), $developers);
        $results = $this->skillMatcher->rankCandidates($matchingOffer, $candidates);
        $semanticMatches = $this->semanticMatchingService->scoreCandidates($matchingOffer, $candidates);
        $enrichedMatches = $this->enrichedMatchingService->scoreCandidates($matchingOffer, $candidates, $semanticMatches['scores']);

        $developerById = [];
        foreach ($developers as $developer) {
            $developerById[(string) $developer->getId()] = $developer;
        }

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

        $headlineAndBio = trim(sprintf('%s %s', (string) $developer->getHeadline(), (string) $developer->getBio()));
        $hardSkills = [...$hardSkills, ...$this->extractHardSkills($headlineAndBio)];
        $softSkills = [...$softSkills, ...$this->extractSoftSkills($headlineAndBio)];

        $cv = $this->buildCvText($developer);
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

    private function buildCvText(DeveloperProfile $developer): string
    {
        $segments = [
            (string) $developer->getHeadline(),
            (string) $developer->getBio(),
        ];

        foreach ($developer->getExperiences() as $experience) {
            $segments[] = trim(sprintf(
                '%s %s %s',
                (string) $experience->getCompanyName(),
                (string) $experience->getTitle(),
                (string) $experience->getDescription(),
            ));
        }

        return trim(implode(' ', array_filter($segments, static fn (string $segment): bool => '' !== trim($segment))));
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
