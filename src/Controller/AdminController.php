<?php

namespace App\Controller;

use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\AdminActionLogRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    /** @var string[] */
    private const ALLOWED_ROLES = ['ROLE_APPLICANT', 'ROLE_RECRUITER', 'ROLE_ADMIN'];

    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(
        UserRepository $userRepository,
        DeveloperProfileRepository $profileRepository,
        CompanyRepository $companyRepository,
        ContactMessageRepository $contactMessageRepository,
        AdminActionLogRepository $adminActionLogRepository,
        NotificationManager $notificationManager,
    ): Response {
        $notificationManager->notifyAdminsOldPendingAccounts();

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

    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function users(UserRepository $userRepository, Request $request, NotificationManager $notificationManager): Response
    {
        $notificationManager->notifyAdminsOldPendingAccounts();

        $requestedRole = (string) $request->query->get('role', '');
        $requestedStatus = (string) $request->query->get('status', '');
        $search = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 12;

        $allowedStatuses = array_map(static fn (UserStatus $status): string => $status->value, UserStatus::cases());
        $role = in_array($requestedRole, self::ALLOWED_ROLES, true) ? $requestedRole : null;
        $status = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : null;

        $result = $userRepository->findAdminUsersPaginated($role, $status, '' !== $search ? $search : null, $page, $perPage);
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $totalPages);

        if ($currentPage !== $page) {
            $result = $userRepository->findAdminUsersPaginated($role, $status, '' !== $search ? $search : null, $currentPage, $perPage);
        }

        return $this->render('admin/users.html.twig', [
            'users' => $result['users'],
            'totalUsersFiltered' => $total,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'pendingUsersCount' => $userRepository->count(['status' => UserStatus::PENDING]),
            'filters' => [
                'role' => $role,
                'status' => $status,
                'q' => $search,
            ],
        ]);
    }

    #[Route('/users/{id}/role', name: 'app_admin_users_update_role', methods: ['POST'])]
    public function updateUserRole(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_role_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $requestedRole = (string) $request->request->get('role');
        if (!in_array($requestedRole, self::ALLOWED_ROLES, true)) {
            $this->addFlash('error', 'Role invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && 'ROLE_ADMIN' !== $requestedRole) {
            $this->addFlash('error', 'Impossible de retirer votre propre rôle administrateur.');

            return $this->redirectToRoute('app_admin_users');
        }

        $currentRole = $this->extractPrimaryRole($user);
        $user->setRoles([$requestedRole]);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $notificationManager->notifyUserRoleChanged($user, $currentRole, $requestedRole);
        if ($currentUser instanceof User) {
            $notificationManager->notifyAdminRoleAction($currentUser, $user, $currentRole, $requestedRole);
        }

        $this->logAdminAction($entityManager, 'user.role_changed', $user, [
            'previousRole' => $currentRole,
            'newRole' => $requestedRole,
        ]);

        $entityManager->flush();
        $this->addFlash('success', 'Le rôle de l\'utilisateur a été mis à jour.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/status', name: 'app_admin_users_update_status', methods: ['POST'])]
    public function updateUserStatus(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_status_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $status = UserStatus::tryFrom((string) $request->request->get('status'));
        if (!$status instanceof UserStatus) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && in_array($status, [UserStatus::BANNED, UserStatus::DELETED], true)) {
            $this->addFlash('error', 'Impossible de bannir ou supprimer votre propre compte.');

            return $this->redirectToRoute('app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $user->setStatus($status);
        $user->setUpdatedAt(new \DateTimeImmutable());
        if (UserStatus::ACTIVE === $status) {
            $user->setIsVerified(true);
        }

        $notificationManager->notifyUserStatusChanged($user, $previousStatus, $status);
        if ($currentUser instanceof User) {
            $notificationManager->notifyAdminStatusAction($currentUser, $user, $previousStatus, $status);
        }

        $this->logAdminAction($entityManager, 'user.status_changed', $user, [
            'previousStatus' => $previousStatus,
            'newStatus' => $status->value,
        ]);

        $entityManager->flush();
        $this->addFlash('success', 'Le statut de l\'utilisateur a été mis à jour.');

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/suspend', name: 'app_admin_users_suspend', methods: ['POST'])]
    public function suspendUser(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        return $this->applyStatusAction($user, $request, $entityManager, $notificationManager, UserStatus::SUSPENDED, 'admin_user_suspend_', 'user.suspended', 'Compte suspendu.');
    }

    #[Route('/users/{id}/ban', name: 'app_admin_users_ban', methods: ['POST'])]
    public function banUser(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        return $this->applyStatusAction($user, $request, $entityManager, $notificationManager, UserStatus::BANNED, 'admin_user_ban_', 'user.banned', 'Compte banni.');
    }

    #[Route('/users/{id}/validate', name: 'app_admin_users_validate', methods: ['POST'])]
    public function validateUser(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        return $this->applyStatusAction($user, $request, $entityManager, $notificationManager, UserStatus::ACTIVE, 'admin_user_validate_', 'user.validated', 'Compte valide.');
    }

    #[Route('/users/{id}/reject', name: 'app_admin_users_reject', methods: ['POST'])]
    public function rejectPendingUser(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_reject_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $currentUser = $this->getUser();
        $conn = $entityManager->getConnection();

        // On refuse le compte sans supprimer l'utilisateur pour conserver la notification de refus.
        try {
            // 1. Supprimer les données de profil liées pour éviter de laisser des ressources orphelines.
            $devProfileId = $user->getDeveloperProfile()?->getId();
            if ($devProfileId) {
                $conn->executeStatement('DELETE FROM contact_message WHERE developer_profile_id = ?', [$devProfileId]);
                $conn->executeStatement('DELETE FROM education WHERE developer_profile_id = ?', [$devProfileId]);
                $conn->executeStatement('DELETE FROM experience WHERE developer_profile_id = ?', [$devProfileId]);
                $conn->executeStatement('DELETE FROM profile_skill WHERE developer_profile_id = ?', [$devProfileId]);
                $conn->executeStatement('DELETE FROM favorite_profile WHERE developer_profile_id = ?', [$devProfileId]);
            }

            $recruiterProfileId = $user->getRecruiterProfile()?->getId();
            if ($recruiterProfileId) {
                $conn->executeStatement('DELETE FROM favorite_profile WHERE recruiter_profile_id = ?', [$recruiterProfileId]);
                $conn->executeStatement('DELETE FROM job_offer WHERE recruiter_profile_id = ?', [$recruiterProfileId]);
            }

            // 2. Supprimer les profils rattachés.
            if ($user->getDeveloperProfile()) {
                $entityManager->remove($user->getDeveloperProfile());
            }
            if ($user->getRecruiterProfile()) {
                $entityManager->remove($user->getRecruiterProfile());
            }

            // 3. Marquer le compte comme refusé.
            $user->setStatus(UserStatus::DELETED);
            $user->setUpdatedAt(new \DateTimeImmutable());

            $notificationManager->notifyUserStatusChanged($user, $previousStatus, UserStatus::DELETED);
            if ($currentUser instanceof User) {
                $notificationManager->notifyAdminStatusAction($currentUser, $user, $previousStatus, UserStatus::DELETED);
            }

            // Log l'action AVANT de flush.
            $this->logAdminAction($entityManager, 'user.rejected', $user, [
                'targetUserId' => $user->getId(),
                'targetUserEmail' => $user->getEmail(),
                'previousStatus' => $previousStatus,
                'newStatus' => 'deleted',
            ]);

            $entityManager->flush();

            $this->addFlash('success', 'Compte refusé et marqué comme supprimé.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors du refus du compte: ' . $e->getMessage());
            
            return $this->redirectToRoute('app_admin_users');
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/delete', name: 'app_admin_users_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRoute('app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $userId = $user->getId();
        $userEmail = $user->getEmail();
        $conn = $entityManager->getConnection();

        // Supprimer les données liées dans le bon ordre pour éviter les FK violations
        try {
            // 1. Supprimer les ContactMessages liés au DeveloperProfile
            $devProfileId = $user->getDeveloperProfile()?->getId();
            if ($devProfileId) {
                $conn->executeStatement(
                    'DELETE FROM contact_message WHERE developer_profile_id = ?',
                    [$devProfileId]
                );
                
                // 2. Supprimer les Education du DeveloperProfile
                $conn->executeStatement(
                    'DELETE FROM education WHERE developer_profile_id = ?',
                    [$devProfileId]
                );
                
                // 3. Supprimer les Experience du DeveloperProfile
                $conn->executeStatement(
                    'DELETE FROM experience WHERE developer_profile_id = ?',
                    [$devProfileId]
                );
                
                // 4. Supprimer les ProfileSkill du DeveloperProfile
                $conn->executeStatement(
                    'DELETE FROM profile_skill WHERE developer_profile_id = ?',
                    [$devProfileId]
                );
                
                // 5. Supprimer les FavoriteProfile qui pointent au DeveloperProfile
                $conn->executeStatement(
                    'DELETE FROM favorite_profile WHERE developer_profile_id = ?',
                    [$devProfileId]
                );
            }

            // 6. Supprimer les FavoriteProfile liés au RecruiterProfile
            $recruiterProfileId = $user->getRecruiterProfile()?->getId();
            if ($recruiterProfileId) {
                $conn->executeStatement(
                    'DELETE FROM favorite_profile WHERE recruiter_profile_id = ?',
                    [$recruiterProfileId]
                );
                
                // 7. Supprimer les JobOffer du RecruiterProfile
                $conn->executeStatement(
                    'DELETE FROM job_offer WHERE recruiter_profile_id = ?',
                    [$recruiterProfileId]
                );
            }

            // 8. Supprimer les profils
            if ($user->getDeveloperProfile()) {
                $entityManager->remove($user->getDeveloperProfile());
            }
            if ($user->getRecruiterProfile()) {
                $entityManager->remove($user->getRecruiterProfile());
            }

            // 9. Supprimer l'utilisateur
            $entityManager->remove($user);

            // Log l'action AVANT de flush (car après l'utilisateur n'existe plus)
            $this->logAdminAction($entityManager, 'user.deleted', null, [
                'targetUserId' => $userId,
                'targetUserEmail' => $userEmail,
                'previousStatus' => $previousStatus,
                'newStatus' => 'deleted',
            ]);

            $entityManager->flush();

            $this->addFlash('success', 'Compte utilisateur supprime definitiement avec toutes ses donnees.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression du compte: ' . $e->getMessage());
            
            return $this->redirectToRoute('app_admin_users');
        }

        return $this->redirectToRoute('app_admin_users');
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

    private function applyStatusAction(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationManager $notificationManager,
        UserStatus $newStatus,
        string $csrfPrefix,
        string $logAction,
        string $successMessage
    ): Response {
        if (!$this->isCsrfTokenValid($csrfPrefix . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && in_array($newStatus, [UserStatus::BANNED, UserStatus::DELETED], true)) {
            $this->addFlash('error', 'Action interdite sur votre propre compte.');

            return $this->redirectToRoute('app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $user->setStatus($newStatus);
        $user->setUpdatedAt(new \DateTimeImmutable());
        if (UserStatus::ACTIVE === $newStatus) {
            $user->setIsVerified(true);
        }

        $notificationManager->notifyUserStatusChanged($user, $previousStatus, $newStatus);
        if ($currentUser instanceof User) {
            $notificationManager->notifyAdminStatusAction($currentUser, $user, $previousStatus, $newStatus);
        }

        $this->logAdminAction($entityManager, $logAction, $user, [
            'previousStatus' => $previousStatus,
            'newStatus' => $newStatus->value,
        ]);

        $entityManager->flush();
        $this->addFlash('success', $successMessage);

        return $this->redirectToRoute('app_admin_users');
    }

    private function extractPrimaryRole(User $user): string
    {
        $roles = $user->getRoles();
        foreach (self::ALLOWED_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return 'ROLE_USER';
    }

    private function logAdminAction(
        EntityManagerInterface $entityManager,
        string $action,
        ?User $targetUser = null,
        ?array $metadata = null,
        ?string $reason = null
    ): void {
        $adminUser = $this->getUser();
        if (!$adminUser instanceof User) {
            return;
        }

        $log = new AdminActionLog();
        $log->setAction($action);
        $log->setReason($reason);
        $log->setMetadata($metadata);
        $log->setCreatedAt(new \DateTimeImmutable());
        $log->setAdminUser($adminUser);
        $log->setTargetUser($targetUser);

        $entityManager->persist($log);
    }
}
