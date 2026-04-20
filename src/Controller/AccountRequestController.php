<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DeveloperProfileRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountRequestController extends AbstractController
{
    #[Route('/account/request-role', name: 'app_account_request_role', methods: ['POST'])]
    public function requestRoleChange(Request $request, NotificationManager $notificationManager, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('request_role', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, $this->resolveDashboardRoute($user)));
        }

        $requestedRole = (string) $request->request->get('role');
        if (!in_array($requestedRole, ['ROLE_APPLICANT', 'ROLE_RECRUITER'], true)) {
            $this->addFlash('error', 'Rôle demandé invalide.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, $this->resolveDashboardRoute($user)));
        }

        if (in_array($requestedRole, $user->getRoles(), true)) {
            $this->addFlash('info', 'Votre compte possède déjà ce rôle.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, $this->resolveDashboardRoute($user)));
        }

        $created = $notificationManager->notifyAdminsRoleRequest($user, $requestedRole);
        if ($created) {
            $entityManager->flush();
            $this->addFlash('success', 'Votre demande de changement de rôle a été transmise aux administrateurs.');
        } else {
            $this->addFlash('info', 'Une demande identique est déjà en attente côté administrateur.');
        }

        return $this->redirectToRoute($this->resolveRedirectRoute($request, $this->resolveDashboardRoute($user)));
    }

    #[Route('/account/request-slug', name: 'app_account_request_slug', methods: ['POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function requestSlugChange(
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        NotificationManager $notificationManager,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('request_slug', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
        }

        $profile = $user->getDeveloperProfile();
        if (null === $profile) {
            $this->addFlash('error', 'Aucun profil développeur associé à ce compte.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
        }

        $desiredSlug = $this->sanitizeSlug((string) $request->request->get('desired_slug'));
        if ('' === $desiredSlug || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $desiredSlug)) {
            $this->addFlash('error', 'Le slug demandé est invalide. Utilisez uniquement des lettres minuscules, chiffres et tirets.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
        }

        if ($desiredSlug === $profile->getSlug()) {
            $this->addFlash('info', 'Le slug demandé est déjà celui de votre portfolio.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
        }

        $existingProfile = $developerProfileRepository->findOneBy(['slug' => $desiredSlug]);
        if (null !== $existingProfile && $existingProfile->getId() !== $profile->getId()) {
            $this->addFlash('error', 'Ce slug est déjà utilisé par un autre portfolio.');

            return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
        }

        $created = $notificationManager->notifyAdminsSlugChangeRequest($user, $profile, $desiredSlug);
        if ($created) {
            $entityManager->flush();
            $this->addFlash('success', 'Votre demande de changement de slug a été envoyée aux administrateurs.');
        } else {
            $this->addFlash('info', 'Une demande identique existe déjà.');
        }

        return $this->redirectToRoute($this->resolveRedirectRoute($request, 'app_applicant_home'));
    }

    private function resolveDashboardRoute(User $user): string
    {
        if (in_array('ROLE_APPLICANT', $user->getRoles(), true)) {
            return 'app_applicant_home';
        }

        if (in_array('ROLE_RECRUITER', $user->getRoles(), true)) {
            return 'app_recruiter_home';
        }

        return 'app_home';
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = mb_strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function resolveRedirectRoute(Request $request, string $fallbackRoute): string
    {
        $requestedRoute = (string) $request->request->get('_redirect_route', '');
        $allowedRoutes = [
            'app_settings_role',
            'app_settings_slug',
            'app_applicant_home',
            'app_recruiter_home',
            'app_home',
        ];

        if (in_array($requestedRoute, $allowedRoutes, true)) {
            return $requestedRoute;
        }

        return $fallbackRoute;
    }
}
