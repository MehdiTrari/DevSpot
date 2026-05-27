<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    #[Route(path: '/home', name: 'app_home_alias')]
    public function home(): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToDashboard();
        }

        $response = $this->render('home.html.twig');
        $response->setPublic();
        $response->setSharedMaxAge(60);
        $response->setMaxAge(60);

        return $response;
    }

    #[Route(path: '/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        if (null === $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToDashboard();
    }

    #[Route(path: '/help', name: 'app_help')]
    public function help(): Response
    {
        return $this->render('help.html.twig');
    }

    #[Route(path: '/faq', name: 'app_faq')]
    public function faq(): Response
    {
        return $this->render('support.html.twig');
    }

    private function redirectToDashboard(): RedirectResponse
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_dashboard_alias');
        }

        if ($this->isGranted('ROLE_RECRUITER')) {
            return $this->redirectToRoute('app_recruiter_dashboard');
        }

        if ($this->isGranted('ROLE_APPLICANT')) {
            return $this->redirectToRoute('app_applicant_dashboard');
        }

        return $this->redirectToRoute('app_settings_index');
    }
}
