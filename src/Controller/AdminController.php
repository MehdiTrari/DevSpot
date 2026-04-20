<?php

namespace App\Controller;

use App\Entity\AdminActionLog;
use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\AdminActionLogRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    /** @var string[] */
    private const ALLOWED_ROLES = ['ROLE_APPLICANT', 'ROLE_RECRUITER', 'ROLE_ADMIN'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

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

        $stats = [
            'totalUsers' => $userRepository->count([]),
            'totalApplicants' => $this->countUsersByRole($userRepository, 'ROLE_APPLICANT'),
            'totalRecruiters' => $this->countUsersByRole($userRepository, 'ROLE_RECRUITER'),
            'totalProfiles' => $profileRepository->count([]),
            'totalCompanies' => $companyRepository->count([]),
            'totalMessages' => $contactMessageRepository->count([]),
            'unreadMessages' => $contactMessageRepository->count(['isRead' => false]),
        ];

        $recentActions = $adminActionLogRepository->findBy([], ['createdAt' => 'DESC'], 5);
        $recentUsers = $userRepository->findBy([], ['createdAt' => 'DESC'], 5);
        $recentMessages = $contactMessageRepository->findBy([], ['createdAt' => 'DESC'], 5);

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
        $perPage = 10;

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

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $requestedRole = (string) $request->request->get('role');
        if (!in_array($requestedRole, self::ALLOWED_ROLES, true)) {
            $this->addFlash('error', 'Role invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && 'ROLE_ADMIN' !== $requestedRole) {
            $this->addFlash('error', 'Impossible de retirer votre propre rôle administrateur.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentRole = $this->extractPrimaryRole($user);
        $shouldNotifyTargetUser = !$this->isApplicantUser($user);
        $user->setRoles([$requestedRole]);
        $user->setUpdatedAt(new \DateTimeImmutable());

        if ($shouldNotifyTargetUser) {
            $notificationManager->notifyUserRoleChanged($user, $currentRole, $requestedRole);
        }
        if ($currentUser instanceof User) {
            $notificationManager->notifyAdminRoleAction($currentUser, $user, $currentRole, $requestedRole);
        }

        $this->logAdminAction($entityManager, 'user.role_changed', $user, [
            'previousRole' => $currentRole,
            'newRole' => $requestedRole,
        ]);

        $entityManager->flush();
        $this->addFlash('success', 'Le rôle de l\'utilisateur a été mis à jour.');

        return $this->redirectToRefererOrRoute($request, 'app_admin_users');
    }

    #[Route('/users/{id}/status', name: 'app_admin_users_update_status', methods: ['POST'])]
    public function updateUserStatus(User $user, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_status_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $status = UserStatus::tryFrom((string) $request->request->get('status'));
        if (!$status instanceof UserStatus) {
            $this->addFlash('error', 'Statut invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && in_array($status, [UserStatus::BANNED, UserStatus::DELETED], true)) {
            $this->addFlash('error', 'Impossible de bannir ou supprimer votre propre compte.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
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

        return $this->redirectToRefererOrRoute($request, 'app_admin_users');
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
    public function rejectPendingUser(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_reject_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $userId = $user->getId();
        $userEmail = $user->getEmail();

        try {
            $this->removeUserDataGraph($user, $entityManager);
            $this->logAdminAction($entityManager, 'user.rejected', null, [
                'targetUserId' => $userId,
                'targetUserEmail' => $userEmail,
                'previousStatus' => $previousStatus,
                'newStatus' => 'deleted',
            ]);

            $entityManager->flush();

            $this->addFlash('success', 'Compte refuse et supprime definitiement avec toutes ses donnees.');
        } catch (\Throwable $exception) {
            $this->logger->error('Le refus d\'un compte utilisateur a echoue.', [
                'userId' => $userId,
                'error' => $exception->getMessage(),
            ]);
            $this->addFlash('error', 'Erreur lors du refus du compte. Merci de reessayer.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        return $this->redirectToRefererOrRoute($request, 'app_admin_users');
    }

    #[Route('/users/{id}/delete', name: 'app_admin_users_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Impossible de supprimer votre propre compte.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $previousStatus = $user->getStatus()?->value;
        $userId = $user->getId();
        $userEmail = $user->getEmail();

        try {
            $this->removeUserDataGraph($user, $entityManager);
            $this->logAdminAction($entityManager, 'user.deleted', null, [
                'targetUserId' => $userId,
                'targetUserEmail' => $userEmail,
                'previousStatus' => $previousStatus,
                'newStatus' => 'deleted',
            ]);

            $entityManager->flush();

            $this->addFlash('success', 'Compte utilisateur supprime definitiement avec toutes ses donnees.');
        } catch (\Throwable $exception) {
            $this->logger->error('La suppression d\'un compte utilisateur a echoue.', [
                'userId' => $userId,
                'error' => $exception->getMessage(),
            ]);
            $this->addFlash('error', 'Erreur lors de la suppression du compte. Merci de reessayer.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        return $this->redirectToRefererOrRoute($request, 'app_admin_users');
    }

    #[Route('/profiles', name: 'app_admin_profiles')]
    public function profiles(DeveloperProfileRepository $profileRepository, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 9;
        $totalProfiles = $profileRepository->count([]);
        $totalPages = max(1, (int) ceil($totalProfiles / $perPage));
        $currentPage = min($page, $totalPages);

        return $this->render('admin/profiles.html.twig', [
            'profiles' => $profileRepository->findAdminProfilesPaginated($currentPage, $perPage),
            'totalProfiles' => $totalProfiles,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/profiles/{id}/moderate', name: 'app_admin_profiles_moderate', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function moderateProfile(DeveloperProfile $profile, Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_profile_moderate_' . $profile->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_profiles');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ('' === $reason) {
            $this->addFlash('error', 'Merci de renseigner un message de moderation.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_profiles');
        }

        $profile->setIsPublic(false);
        $profile->setModeratedAt(new \DateTimeImmutable());
        $profile->setModerationReason($reason);
        $profile->setUpdatedAt(new \DateTimeImmutable());
        $notificationManager->notifyApplicantProfileModerated($profile, $reason);

        $this->logAdminAction($entityManager, 'profile.moderated', $profile->getUser(), [
            'profileId' => $profile->getId(),
            'reason' => $reason,
        ]);

        $entityManager->flush();
        $this->addFlash('success', 'Le profil a été modéré et l\'utilisateur a été notifié.');

        return $this->redirectToRefererOrRoute($request, 'app_admin_profiles');
    }

    #[Route('/companies', name: 'app_admin_companies')]
    public function companies(CompanyRepository $companyRepository, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 9;
        $totalCompanies = $companyRepository->count([]);
        $totalPages = max(1, (int) ceil($totalCompanies / $perPage));
        $currentPage = min($page, $totalPages);

        return $this->render('admin/companies.html.twig', [
            'companies' => $companyRepository->findAdminCompaniesPaginated($currentPage, $perPage),
            'totalCompanies' => $totalCompanies,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/messages', name: 'app_admin_messages')]
    public function messages(ContactMessageRepository $contactMessageRepository, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 10;
        $totalMessages = $contactMessageRepository->count([]);
        $totalPages = max(1, (int) ceil($totalMessages / $perPage));
        $currentPage = min($page, $totalPages);

        return $this->render('admin/messages.html.twig', [
            'messages' => $contactMessageRepository->findAdminMessagesPaginated($currentPage, $perPage),
            'totalMessages' => $totalMessages,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/messages/{id}/delete', name: 'app_admin_messages_delete', methods: ['POST'])]
    public function deleteMessage(ContactMessage $contactMessage, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('admin_message_delete_' . $contactMessage->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_messages');
        }

        $this->logAdminAction($entityManager, 'contact_message.deleted', null, [
            'messageId' => $contactMessage->getId(),
            'recruiterEmail' => $contactMessage->getRecruiterEmail(),
            'recruiterName' => $contactMessage->getRecruiterName(),
            'subject' => $contactMessage->getSubject(),
            'developerProfileId' => $contactMessage->getDeveloperProfile()?->getId(),
        ]);

        $entityManager->remove($contactMessage);
        $entityManager->flush();

        $this->addFlash('success', 'Le message de contact a ete supprime.');

        return $this->redirectToRefererOrRoute($request, 'app_admin_messages');
    }

    private function countUsersByRole(UserRepository $userRepository, string $role): int
    {
        return $userRepository->countByRole($role);
    }

    private function applyStatusAction(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationManager $notificationManager,
        UserStatus $newStatus,
        string $csrfPrefix,
        string $logAction,
        string $successMessage,
    ): Response {
        if (!$this->isCsrfTokenValid($csrfPrefix . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && in_array($newStatus, [UserStatus::BANNED, UserStatus::DELETED], true)) {
            $this->addFlash('error', 'Action interdite sur votre propre compte.');

            return $this->redirectToRefererOrRoute($request, 'app_admin_users');
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

        return $this->redirectToRefererOrRoute($request, 'app_admin_users');
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

    private function isApplicantUser(User $user): bool
    {
        return in_array('ROLE_APPLICANT', $user->getRoles(), true);
    }

    private function redirectToRefererOrRoute(Request $request, string $route, array $parameters = []): Response
    {
        $referer = $request->headers->get('referer');
        $origin = $request->getSchemeAndHttpHost();

        if (is_string($referer) && '' !== $referer && (str_starts_with($referer, '/') || str_starts_with($referer, $origin))) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute($route, $parameters);
    }

    private function logAdminAction(
        EntityManagerInterface $entityManager,
        string $action,
        ?User $targetUser = null,
        ?array $metadata = null,
        ?string $reason = null,
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

    private function removeUserDataGraph(User $user, EntityManagerInterface $entityManager): void
    {
        $developerProfile = $user->getDeveloperProfile();
        if (null !== $developerProfile) {
            foreach ($developerProfile->getContactMessages()->toArray() as $contactMessage) {
                $entityManager->remove($contactMessage);
            }

            foreach ($developerProfile->getEducation()->toArray() as $education) {
                $entityManager->remove($education);
            }

            foreach ($developerProfile->getExperiences()->toArray() as $experience) {
                $entityManager->remove($experience);
            }

            foreach ($developerProfile->getProfileSkills()->toArray() as $profileSkill) {
                $entityManager->remove($profileSkill);
            }

            foreach ($developerProfile->getFavoriteProfiles()->toArray() as $favoriteProfile) {
                $entityManager->remove($favoriteProfile);
            }

            $developerProfile->getDesiredPositions()->clear();
            $entityManager->remove($developerProfile);
        }

        $recruiterProfile = $user->getRecruiterProfile();
        if (null !== $recruiterProfile) {
            foreach ($recruiterProfile->getFavoriteProfiles()->toArray() as $favoriteProfile) {
                $entityManager->remove($favoriteProfile);
            }

            foreach ($recruiterProfile->getJobOffers()->toArray() as $jobOffer) {
                $entityManager->remove($jobOffer);
            }

            $entityManager->remove($recruiterProfile);
        }

        foreach ($user->getNotifications()->toArray() as $notification) {
            $entityManager->remove($notification);
        }

        $conversations = [];
        foreach ($user->getConversations()->toArray() as $conversation) {
            $conversations[spl_object_hash($conversation)] = $conversation;
        }
        foreach ($user->getConversationsRecruiter()->toArray() as $conversation) {
            $conversations[spl_object_hash($conversation)] = $conversation;
        }

        foreach ($conversations as $conversation) {
            foreach ($conversation->getMessages()->toArray() as $message) {
                $entityManager->remove($message);
            }

            $entityManager->remove($conversation);
        }

        foreach ($user->getActivityLogs()->toArray() as $activityLog) {
            $activityLog->setUser(null);
        }

        foreach ($user->getAdminActionLogs()->toArray() as $adminActionLog) {
            $adminActionLog->setAdminUser(null);
        }

        foreach ($user->getTargetedAdminActionLogs()->toArray() as $adminActionLog) {
            $adminActionLog->setTargetUser(null);
        }

        $entityManager->remove($user);
    }
}
