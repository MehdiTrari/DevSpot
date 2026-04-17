<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use App\Repository\FavoriteProfileRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notificationRepository,
        private readonly FavoriteProfileRepository $favoriteProfileRepository,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function notifyAdminsNewPendingAccount(User $pendingUser): void
    {
        if (UserStatus::PENDING !== $pendingUser->getStatus()) {
            return;
        }

        $profileLabel = $this->resolveProfileLabel($pendingUser);
        $link = $this->urlGenerator->generate('app_admin_users', ['status' => 'pending']);

        foreach ($this->userRepository->findAdmins() as $admin) {
            $this->createNotification(
                $admin,
                NotificationType::NEW_USER_PENDING,
                'Nouveau compte en attente',
                sprintf('%s (%s) attend une validation administrateur.', $pendingUser->getEmail() ?? 'Compte inconnu', $profileLabel),
                $link
            );
        }

        $this->entityManager->flush();
    }

    public function notifyAdminsOldPendingAccounts(int $olderThanDays = 7): void
    {
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $olderThanDays));
        $pendingUsers = $this->userRepository->findPendingOlderThan($cutoff);

        if ([] === $pendingUsers) {
            return;
        }

        $admins = $this->userRepository->findAdmins();
        if ([] === $admins) {
            return;
        }

        foreach ($pendingUsers as $pendingUser) {
            $daysPending = max(1, (new \DateTimeImmutable())->diff($pendingUser->getCreatedAt() ?? new \DateTimeImmutable())->days);
            $profileLabel = $this->resolveProfileLabel($pendingUser);
            $link = $this->urlGenerator->generate('app_admin_users', [
                'status' => 'pending',
                'q' => $pendingUser->getEmail(),
            ]);

            foreach ($admins as $admin) {
                if ($this->notificationRepository->existsForUserTypeAndLink($admin, NotificationType::SYSTEM_NOTIFICATION, $link)) {
                    continue;
                }

                $this->createNotification(
                    $admin,
                    NotificationType::SYSTEM_NOTIFICATION,
                    'Relance pending trop ancien',
                    sprintf('%s (%s) est en attente depuis %d jour(s).', $pendingUser->getEmail() ?? 'Compte inconnu', $profileLabel, $daysPending),
                    $link
                );
            }
        }

        $this->entityManager->flush();
    }

    public function notifyUserRoleChanged(User $targetUser, string $previousRole, string $newRole): void
    {
        if ($previousRole === $newRole) {
            return;
        }

        $this->createNotification(
            $targetUser,
            NotificationType::SYSTEM_NOTIFICATION,
            'Votre rôle a été modifié',
            sprintf(
                'Un administrateur a modifié votre rôle: %s -> %s.',
                $this->toReadableRole($previousRole),
                $this->toReadableRole($newRole)
            ),
            $this->urlGenerator->generate('app_home')
        );
    }

    public function notifyAdminRoleAction(User $adminUser, User $targetUser, string $previousRole, string $newRole): void
    {
        if ($previousRole === $newRole) {
            return;
        }

        $this->createNotification(
            $adminUser,
            NotificationType::SYSTEM_NOTIFICATION,
            'Action admin effectuée',
            sprintf(
                'Vous avez modifié le rôle de %s: %s -> %s.',
                $targetUser->getEmail() ?? 'compte inconnu',
                $this->toReadableRole($previousRole),
                $this->toReadableRole($newRole)
            ),
            $this->urlGenerator->generate('app_admin_users')
        );
    }

    public function notifyUserStatusChanged(User $targetUser, ?string $previousStatus, UserStatus $newStatus): void
    {
        $type = match ($newStatus) {
            UserStatus::ACTIVE => NotificationType::ACCOUNT_APPROVED,
            UserStatus::SUSPENDED => NotificationType::ACCOUNT_SUSPENDED,
            UserStatus::BANNED => NotificationType::ACCOUNT_BANNED,
            UserStatus::PENDING => NotificationType::ACCOUNT_PENDING,
            UserStatus::DELETED => NotificationType::ACCOUNT_REJECTED,
        };

        $title = match ($newStatus) {
            UserStatus::ACTIVE => 'Votre compte a été accepté',
            UserStatus::DELETED => 'Votre compte a été refusé',
            UserStatus::SUSPENDED => 'Votre compte a été suspendu',
            UserStatus::BANNED => 'Votre compte a été banni',
            UserStatus::PENDING => 'Votre compte est en attente de validation',
        };

        $content = match ($newStatus) {
            UserStatus::ACTIVE => sprintf(
                'Votre compte a été accepté par un administrateur. Statut précédent : %s.',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU'
            ),
            UserStatus::DELETED => sprintf(
                'Votre compte a été refusé par un administrateur. Statut précédent : %s.',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU'
            ),
            UserStatus::SUSPENDED => sprintf(
                'Votre compte a été suspendu. Statut précédent : %s.',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU'
            ),
            UserStatus::BANNED => sprintf(
                'Votre compte a été banni. Statut précédent : %s.',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU'
            ),
            UserStatus::PENDING => 'Votre compte est toujours en attente de validation.',
        };

        $this->createNotification(
            $targetUser,
            $type,
            $title,
            $content,
            $this->urlGenerator->generate('app_home')
        );
    }

    public function notifyApplicantNewMessage(ContactMessage $contactMessage): void
    {
        // Message notifications are intentionally disabled.
    }

    public function notifyConversationNewMessage(Message $message): void
    {
        // Message notifications are intentionally disabled.
    }

    public function notifyRecruitersFollowingProfileUpdated(DeveloperProfile $profile): void
    {
        $this->notifyRecruitersFollowingProfileEvent(
            $profile,
            NotificationType::PROFILE_UPDATED,
            'Profil favori mis à jour',
            sprintf('Le profil %s a été mis à jour.', $this->resolveDeveloperProfileLabel($profile)),
            $this->urlGenerator->generate('app_public_profile_show', ['slug' => (string) $profile->getSlug()])
        );
    }

    public function notifyRecruitersFollowingProfileVisibilityChanged(DeveloperProfile $profile, bool $isPublic): void
    {
        $type = $isPublic ? NotificationType::PROFILE_PUBLISHED : NotificationType::PROFILE_UNPUBLISHED;
        $title = $isPublic ? 'Profil rendu public' : 'Profil rendu privé';
        $content = $isPublic
            ? sprintf('Le profil %s est désormais public.', $this->resolveDeveloperProfileLabel($profile))
            : sprintf('Le profil %s est désormais privé.', $this->resolveDeveloperProfileLabel($profile));

        $this->notifyRecruitersFollowingProfileEvent(
            $profile,
            $type,
            $title,
            $content,
            $isPublic ? $this->urlGenerator->generate('app_public_profile_show', ['slug' => (string) $profile->getSlug()]) : null
        );
    }

    public function notifyAdminStatusAction(User $adminUser, User $targetUser, ?string $previousStatus, UserStatus $newStatus): void
    {
        $this->createNotification(
            $adminUser,
            NotificationType::SYSTEM_NOTIFICATION,
            'Action admin effectuée',
            sprintf(
                'Vous avez modifié le statut de %s: %s -> %s.',
                $targetUser->getEmail() ?? 'compte inconnu',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU',
                strtoupper($newStatus->value)
            ),
            $this->urlGenerator->generate('app_admin_users')
        );
    }

    public function notifyAdminsRoleRequest(User $requester, string $requestedRole): bool
    {
        $link = $this->urlGenerator->generate('app_admin_users', [
            'q' => $requester->getEmail(),
            'requestedRole' => $requestedRole,
        ]);

        return $this->broadcastToAdmins(
            NotificationType::ROLE_REQUEST,
            'Demande de changement de rôle',
            sprintf(
                '%s demande le passage de %s vers %s.',
                $requester->getEmail() ?? 'Utilisateur inconnu',
                $this->toReadableRole($this->extractPrimaryRole($requester)),
                $this->toReadableRole($requestedRole)
            ),
            $link
        );
    }

    public function notifyAdminsSlugChangeRequest(User $requester, DeveloperProfile $profile, string $desiredSlug): bool
    {
        $link = sprintf(
            '%s?requestedSlug=%s',
            $this->urlGenerator->generate('app_public_profile_show', ['slug' => $profile->getSlug()]),
            rawurlencode($desiredSlug)
        );

        return $this->broadcastToAdmins(
            NotificationType::SLUG_CHANGE_REQUEST,
            'Demande de changement de slug',
            sprintf(
                '%s demande le slug "%s" pour le portfolio de %s %s.',
                $requester->getEmail() ?? 'Utilisateur inconnu',
                $desiredSlug,
                $profile->getFirstName() ?? '',
                $profile->getLastName() ?? ''
            ),
            $link
        );
    }

    public function notifyAdminsCompanyCreated(User $requester, Company $company): bool
    {
        $link = sprintf(
            '%s?company=%s',
            $this->urlGenerator->generate('app_admin_companies'),
            rawurlencode((string) $company->getName())
        );

        return $this->broadcastToAdmins(
            NotificationType::SYSTEM_NOTIFICATION,
            'Nouvelle entreprise créée',
            sprintf(
                'L\'entreprise %s a été créée lors de l\'inscription de %s.',
                $company->getName() ?? 'Entreprise inconnue',
                $requester->getEmail() ?? 'un recruteur'
            ),
            $link
        );
    }

    public function notifyAdminsProfileReported(User $reporter, DeveloperProfile $profile, string $reason, bool $abusiveContent = false): bool
    {
        $link = sprintf(
            '%s?reporter=%s&category=%s',
            $this->urlGenerator->generate('app_public_profile_show', ['slug' => $profile->getSlug()]),
            rawurlencode((string) ($reporter->getEmail() ?? 'unknown')),
            $abusiveContent ? 'abusive_content' : 'profile'
        );

        return $this->broadcastToAdmins(
            NotificationType::CONTENT_REPORTED,
            $abusiveContent ? 'Signalement de contenu abusif' : 'Profil signalé',
            sprintf(
                '%s a signalé le profil de %s %s. Motif : %s',
                $reporter->getEmail() ?? 'Utilisateur inconnu',
                $profile->getFirstName() ?? '',
                $profile->getLastName() ?? '',
                $reason
            ),
            $link
        );
    }

    private function createNotification(User $targetUser, NotificationType $type, string $title, string $content, ?string $link = null): void
    {
        $notification = new Notification();
        $notification->setUser($targetUser);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setContent($content);
        $notification->setLink($link);
        $notification->setIsRead(false);

        $this->entityManager->persist($notification);
    }

    private function broadcastToAdmins(NotificationType $type, string $title, string $content, ?string $link = null): bool
    {
        $admins = $this->userRepository->findAdmins();
        if ([] === $admins) {
            return false;
        }

        $created = false;
        foreach ($admins as $admin) {
            if (null !== $link && $this->notificationRepository->existsForUserTypeAndLink($admin, $type, $link)) {
                continue;
            }

            $this->createNotification($admin, $type, $title, $content, $link);
            $created = true;
        }

        return $created;
    }

    private function notifyRecruitersFollowingProfileEvent(DeveloperProfile $profile, NotificationType $type, string $title, string $content, ?string $link): void
    {
        $notifiedUsers = [];

        foreach ($this->favoriteProfileRepository->findByDeveloperProfile($profile) as $favoriteProfile) {
            $targetUser = $favoriteProfile->getRecruiterProfile()?->getUser();
            if (!$targetUser instanceof User) {
                continue;
            }

            $targetUserId = $targetUser->getId();
            if (null !== $targetUserId && isset($notifiedUsers[$targetUserId])) {
                continue;
            }

            if (null !== $targetUserId) {
                $notifiedUsers[$targetUserId] = true;
            }

            $this->createNotification($targetUser, $type, $title, $content, $link);
        }

        if ([] !== $notifiedUsers) {
            $this->entityManager->flush();
        }
    }

    private function resolveDeveloperProfileLabel(DeveloperProfile $profile): string
    {
        $fullName = trim(sprintf('%s %s', (string) $profile->getFirstName(), (string) $profile->getLastName()));

        return '' !== $fullName ? $fullName : 'ce profil';
    }

    private function resolveProfileLabel(User $user): string
    {
        if (in_array('ROLE_RECRUITER', $user->getRoles(), true)) {
            return 'recruteur';
        }

        if (in_array('ROLE_APPLICANT', $user->getRoles(), true)) {
            return 'applicant';
        }

        return 'utilisateur';
    }

    private function toReadableRole(string $role): string
    {
        return match ($role) {
            'ROLE_ADMIN' => 'Administrateur',
            'ROLE_RECRUITER' => 'Recruteur',
            'ROLE_APPLICANT' => 'Applicant',
            default => $role,
        };
    }

    private function extractPrimaryRole(User $user): string
    {
        foreach (['ROLE_ADMIN', 'ROLE_RECRUITER', 'ROLE_APPLICANT'] as $role) {
            if (in_array($role, $user->getRoles(), true)) {
                return $role;
            }
        }

        return 'ROLE_USER';
    }
}
