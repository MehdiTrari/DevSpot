<?php

namespace App\Service;

use App\Entity\AdminActionLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class AdminModerationLogger
{
    public const BAN = 'BAN';
    public const UNBAN = 'UNBAN';
    public const SUSPEND = 'SUSPEND';
    public const VALIDATE_PROFILE = 'VALIDATE_PROFILE';
    public const ADD_WHITELIST = 'ADD_WHITELIST';
    public const REJECT = 'REJECT';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        string $action,
        User $adminUser,
        ?User $targetUser,
        string $reason,
        ?array $metadata = null,
        bool $flush = false,
    ): AdminActionLog {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new \LogicException('Seuls les administrateurs peuvent journaliser une action de moderation.');
        }

        $reason = trim($reason);
        if (self::BAN === $action && '' === $reason) {
            throw new \InvalidArgumentException('La raison est obligatoire pour un bannissement.');
        }

        $log = (new AdminActionLog())
            ->setAction($action)
            ->setAdminUser($adminUser)
            ->setTargetUser($targetUser)
            ->setReason($reason)
            ->setMetadata($metadata)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $log;
    }
}
