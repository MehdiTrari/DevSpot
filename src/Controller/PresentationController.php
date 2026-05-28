<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PresentationController extends AbstractController
{
    #[Route('/admin/presentation', name: 'app_admin_presentation', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/presentation.html.twig');
    }
}
