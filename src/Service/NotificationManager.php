<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notificationRepository,
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

        $this->createNotification(
            $targetUser,
            $type,
            'Statut du compte mis à jour',
            sprintf(
                'Votre statut de compte a changé: %s -> %s.',
                null !== $previousStatus ? strtoupper($previousStatus) : 'INCONNU',
                strtoupper($newStatus->value)
            ),
            $this->urlGenerator->generate('app_home')
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
}
