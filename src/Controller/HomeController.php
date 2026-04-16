<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DeveloperProfileRepository;
use App\Repository\FavoriteProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    public function home(
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
    ): Response {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $isAnonymous = null === $this->getUser();
        $lastModified = null;

        if ($isAnonymous) {
            $lastModified = $developerProfileRepository->findLatestPublicGeneratedProfileUpdate() ?? new \DateTimeImmutable('@0');
            $cacheProbeResponse = new Response();
            $cacheProbeResponse->setPublic();
            $cacheProbeResponse->setSharedMaxAge(60);
            $cacheProbeResponse->setMaxAge(60);
            $cacheProbeResponse->setLastModified($lastModified);

            if ($cacheProbeResponse->isNotModified($request)) {
                return $cacheProbeResponse;
            }
        }

        $perPage = 10;
        $requestedPage = max(1, $request->query->getInt('page', 1));

        $totalProfiles = $developerProfileRepository->countPublicGeneratedProfiles();
        $totalPages = max(1, (int) ceil($totalProfiles / $perPage));
        $currentPage = min($requestedPage, $totalPages);
        $favoriteProfileIds = [];

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $this->isGranted('ROLE_RECRUITER') && null !== $currentUser->getRecruiterProfile()) {
            $favoriteProfileIds = $favoriteProfileRepository->findFavoriteDeveloperProfileIdsForRecruiterProfile($currentUser->getRecruiterProfile());
        }

        $response = $this->render('home.html.twig', [
            'publicPortfolios' => $developerProfileRepository->findPublicGeneratedProfilesPaginated($currentPage, $perPage),
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalPublicPortfolios' => $totalProfiles,
            'favoriteProfileIds' => $favoriteProfileIds,
        ]);

        if ($isAnonymous) {
            $response->setPublic();
            $response->setSharedMaxAge(60);
            $response->setMaxAge(60);
            $response->setLastModified($lastModified);
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store', true);
        }

        return $response;
    }

    #[Route(path: '/help', name: 'app_help')]
    public function help(): Response
    {
        return $this->render('help.html.twig');
    }
}
