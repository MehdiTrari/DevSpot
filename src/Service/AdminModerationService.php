<?php

namespace App\Service;

use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AdminModerationService
{
    public const ACTION_ADD_WHITELIST = 'ADD_WHITELIST';
    public const ACTION_BAN = 'BAN';
    public const ACTION_UNBAN = 'UNBAN';
    public const ACTION_SUSPEND = 'SUSPEND';
    public const ACTION_VALIDATE_PROFILE = 'VALIDATE_PROFILE';
    public const ACTION_ROLE_UPDATE = 'ROLE_UPDATE';
    public const ACTION_ACCOUNT_REJECTED = 'ACCOUNT_REJECTED';
    public const ACTION_ACCOUNT_DELETED = 'ACCOUNT_DELETED';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function getAvailableActions(): array
    {
        return [
            self::ACTION_ADD_WHITELIST,
            self::ACTION_BAN,
            self::ACTION_UNBAN,
            self::ACTION_SUSPEND,
            self::ACTION_VALIDATE_PROFILE,
            self::ACTION_ROLE_UPDATE,
            self::ACTION_ACCOUNT_REJECTED,
            self::ACTION_ACCOUNT_DELETED,
        ];
    }

    public function logAction(
        string $action,
        ?User $targetUser = null,
        ?string $reason = null,
        ?array $metadata = null,
        bool $flush = false,
    ): AdminActionLog {
        $adminUser = $this->requireAdminUser();
        $normalizedReason = $this->normalizeReason($reason);

        if (self::ACTION_BAN === $action && null === $normalizedReason) {
            throw new \InvalidArgumentException('Le motif du bannissement est obligatoire.');
        }

        $log = new AdminActionLog();
        $log->setAction($action);
        $log->setReason($normalizedReason);
        $log->setMetadata($metadata);
        $log->setAdminUser($adminUser);
        $log->setTargetUser($targetUser);

        $this->entityManager->persist($log);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $log;
    }

    public function logStatusChange(
        User $targetUser,
        ?UserStatus $previousStatus,
        UserStatus $newStatus,
        ?string $reason = null,
        ?array $metadata = null,
        bool $flush = false,
    ): AdminActionLog {
        $action = $this->resolveStatusAction($previousStatus, $newStatus);
        $normalizedReason = $this->normalizeReason($reason);

        if (self::ACTION_BAN === $action && null === $normalizedReason) {
            throw new \InvalidArgumentException('Le motif du bannissement est obligatoire.');
        }

        return $this->logAction(
            $action,
            $targetUser,
            $normalizedReason,
            array_merge([
                'previousStatus' => $previousStatus?->value,
                'newStatus' => $newStatus->value,
            ], $metadata ?? []),
            $flush,
        );
    }

    private function requireAdminUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException('Seuls les administrateurs peuvent enregistrer une action de moderation.');
        }

        return $user;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = null !== $reason ? trim($reason) : null;

        return '' === $reason ? null : $reason;
    }

    private function resolveStatusAction(?UserStatus $previousStatus, UserStatus $newStatus): string
    {
        if (UserStatus::BANNED === $newStatus) {
            return self::ACTION_BAN;
        }

        if (UserStatus::SUSPENDED === $newStatus) {
            return self::ACTION_SUSPEND;
        }

        if (UserStatus::ACTIVE === $newStatus && UserStatus::PENDING === $previousStatus) {
            return self::ACTION_ADD_WHITELIST;
        }

        if (UserStatus::ACTIVE === $newStatus && in_array($previousStatus, [UserStatus::BANNED, UserStatus::SUSPENDED], true)) {
            return self::ACTION_UNBAN;
        }

        return self::ACTION_VALIDATE_PROFILE;
    }
}
