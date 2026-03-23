<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\AdminActionLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(
        UserRepository $userRepository,
        DeveloperProfileRepository $profileRepository,
        CompanyRepository $companyRepository,
        ContactMessageRepository $contactMessageRepository,
        AdminActionLogRepository $adminActionLogRepository
    ): Response {
        // Statistiques globales avec requêtes DQL pour les rôles JSON
        $stats = [
            'totalUsers' => $userRepository->count([]),
            'totalApplicants' => $this->countUsersByRole($userRepository, 'ROLE_APPLICANT'),
            'totalRecruiters' => $this->countUsersByRole($userRepository, 'ROLE_RECRUITER'),
            'totalProfiles' => $profileRepository->count([]),
            'totalCompanies' => $companyRepository->count([]),
            'totalMessages' => $contactMessageRepository->count([]),
            'unreadMessages' => $contactMessageRepository->count(['isRead' => false]),
        ];

        // Dernières actions administrateur
        $recentActions = $adminActionLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );

        // Derniers utilisateurs enregistrés
        $recentUsers = $userRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );

        // Derniers messages de contact
        $recentMessages = $contactMessageRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentActions' => $recentActions,
            'recentUsers' => $recentUsers,
            'recentMessages' => $recentMessages,
        ]);
    }

    #[Route('/users', name: 'app_admin_users')]
    public function users(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/profiles', name: 'app_admin_profiles')]
    public function profiles(DeveloperProfileRepository $profileRepository): Response
    {
        $profiles = $profileRepository->findAll();

        return $this->render('admin/profiles.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/companies', name: 'app_admin_companies')]
    public function companies(CompanyRepository $companyRepository): Response
    {
        $companies = $companyRepository->findAll();

        return $this->render('admin/companies.html.twig', [
            'companies' => $companies,
        ]);
    }

    #[Route('/messages', name: 'app_admin_messages')]
    public function messages(ContactMessageRepository $contactMessageRepository): Response
    {
        $messages = $contactMessageRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/messages.html.twig', [
            'messages' => $messages,
        ]);
    }

    /**
     * Compte les utilisateurs ayant un rôle spécifique
     * Utilise une requête SQL native car les rôles sont stockés en JSON
     */
    private function countUsersByRole(UserRepository $userRepository, string $role): int
    {
        $em = $userRepository->getEntityManager();
        $conn = $em->getConnection();
        
        $sql = 'SELECT COUNT(*) as count FROM "user" WHERE roles::text LIKE ?';
        $result = $conn->executeQuery($sql, ['%' . $role . '%']);
        
        return (int) $result->fetchOne();
    }
}
