<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'app_healthz', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ok');
    }
}
