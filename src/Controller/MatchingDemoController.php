<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeveloperProfileRepository;
use App\Repository\JobOfferRepository;
use App\Service\OfferMatchingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MatchingDemoController extends AbstractController
{
    #[Route('/matching/demo', name: 'app_matching_demo', methods: ['GET'])]
    public function __invoke(
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        JobOfferRepository $jobOfferRepository,
        OfferMatchingService $offerMatchingService,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): Response {
        $developers = array_values(array_filter(
            $developerProfileRepository->findAllForMatching(),
            static fn ($developer): bool => str_ends_with((string) $developer->getUser()?->getEmail(), '@demo.devspot.local'),
        ));
        $offers = array_values(array_filter(
            $jobOfferRepository->findActiveForMatching(),
            static fn ($offer): bool => str_ends_with((string) $offer->getRecruiterProfile()?->getUser()?->getEmail(), '@demo.devspot.local'),
        ));
        $datasetPath = $projectDir.'/docs/matching-demo-dataset.json';
        $perPage = 1;
        $totalPages = max(1, (int) ceil(count($offers) / $perPage));
        $currentPage = min(max(1, $request->query->getInt('page', 1)), $totalPages);
        $visibleOffers = array_slice($offers, ($currentPage - 1) * $perPage, $perPage);
        $visibleOfferMatches = array_map(
            fn ($offer): array => $offerMatchingService->buildSingleOfferMatch($offer, $developers),
            $visibleOffers,
        );
        $showDatasetJson = $request->query->getBoolean('showDataset');
        $offerTitles = array_map(
            static fn ($offer): string => (string) ($offer->getTitle() ?? 'Offre'),
            $offers,
        );

        return $this->render('matching/demo.html.twig', [
            'developersCount' => count($developers),
            'offersCount' => count($offers),
            'matchingMatrix' => $visibleOfferMatches,
            'offerTitles' => $offerTitles,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'datasetPath' => $datasetPath,
            'showDatasetJson' => $showDatasetJson,
            'datasetJson' => $showDatasetJson && is_file($datasetPath) ? (string) file_get_contents($datasetPath) : null,
        ]);
    }
}
