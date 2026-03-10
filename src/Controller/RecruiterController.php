<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RecruiterController extends AbstractController
{
    #[Route('/recruiter', name: 'app_recruiter_home')]
    #[IsGranted('ROLE_RECRUITER')]
    public function home(): Response
    {
        $user = $this->getRecruiterUser();

        return $this->render('recruiter/dashboard.html.twig');
    }

    private function getRecruiterUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
