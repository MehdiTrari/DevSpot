<?php

namespace App\Controller;

use App\Repository\DeveloperProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    public function home(Request $request, DeveloperProfileRepository $developerProfileRepository): Response
    {
        $perPage = 10;
        $requestedPage = max(1, $request->query->getInt('page', 1));

        $totalProfiles = $developerProfileRepository->countPublicGeneratedProfiles();
        $totalPages = max(1, (int) ceil($totalProfiles / $perPage));
        $currentPage = min($requestedPage, $totalPages);

        return $this->render('home.html.twig', [
            'publicPortfolios' => $developerProfileRepository->findPublicGeneratedProfilesPaginated($currentPage, $perPage),
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalPublicPortfolios' => $totalProfiles,
        ]);
    }
}