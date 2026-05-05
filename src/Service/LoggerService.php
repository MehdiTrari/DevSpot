<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LoggerService
{
    public const USER_LOGIN = 'USER_LOGIN';
    public const PROFILE_UPDATE = 'PROFILE_UPDATE';
    public const OFFER_PUBLISHED = 'OFFER_PUBLISHED';
    public const MATCHING_CALCULATED = 'MATCHING_CALCULATED';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        string $action,
        ?User $user = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        bool $flush = true,
    ): ActivityLog {
        $request = $this->requestStack->getCurrentRequest();

        $log = (new ActivityLog())
            ->setAction($action)
            ->setUser($user)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setIpAddress($ipAddress ?? $request?->getClientIp())
            ->setMetadata($metadata);

        $this->entityManager->persist($log);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $log;
    }
}
