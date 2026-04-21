<?php

declare(strict_types=1);

namespace App\Controller;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\FairnessAuditor;
use App\Matching\Service\SkillMatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MatchingPreviewController extends AbstractController
{
    public function __construct(
        private readonly SkillMatcher $skillMatcher,
        private readonly CvAnonymizer $cvAnonymizer,
        private readonly FairnessAuditor $fairnessAuditor,
    ) {
    }

    #[Route('/api/matching/preview', name: 'api_matching_preview', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{offer?: array<string, mixed>, candidates?: array<int, array<string, mixed>>} $payload */
        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $offerData = $payload['offer'] ?? [];
        $candidatesData = $payload['candidates'] ?? [];

        $offer = new JobOffer(
            (string) ($offerData['id'] ?? 'offer-1'),
            (string) ($offerData['title'] ?? ''),
            $this->asStringList($offerData['requiredHardSkills'] ?? []),
            $this->asStringList($offerData['desiredSoftSkills'] ?? []),
            (string) ($offerData['description'] ?? ''),
        );

        $candidates = array_map(function (array $candidateData): CandidateProfile {
            return new CandidateProfile(
                (string) ($candidateData['id'] ?? ''),
                (int) ($candidateData['yearsOfExperience'] ?? 0),
                $this->asStringList($candidateData['hardSkills'] ?? []),
                $this->asStringList($candidateData['softSkills'] ?? []),
                $this->cvAnonymizer->anonymize((string) ($candidateData['rawCv'] ?? '')),
            );
        }, $candidatesData);

        $results = $this->skillMatcher->rankCandidates($offer, $candidates);

        return $this->json([
            'offer' => [
                'id' => $offer->id,
                'title' => $offer->title,
            ],
            'matches' => array_map(static fn ($result): array => [
                'candidateId' => $result->candidate->id,
                'yearsOfExperience' => $result->candidate->yearsOfExperience,
                'score' => $result->score,
                'scoreBreakdown' => $result->scoreBreakdown,
                'anonymizedCv' => $result->candidate->rawCv,
            ], $results),
            'fairness' => $this->fairnessAuditor->auditJuniorBias($results),
        ]);
    }

    /**
     * @return list<string>
     */
    private function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item) && '' !== trim($item) ? trim($item) : null,
            $value,
        )));
    }
}
