<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\JobOffer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LoggerService
{
    public const ACTION_USER_LOGIN = 'USER_LOGIN';
    public const ACTION_PROFILE_UPDATE = 'PROFILE_UPDATE';
    public const ACTION_OFFER_PUBLISHED = 'OFFER_PUBLISHED';
    public const ACTION_MATCHING_CALCULATED = 'MATCHING_CALCULATED';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function log(
        ?User $user,
        string $action,
        object|string|null $entity = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        bool $flush = true,
    ): ActivityLog {
        $log = new ActivityLog();
        $log->setUser($user);
        $log->setAction($action);
        $log->setEntityType($this->resolveEntityType($entity));
        $log->setEntityId($this->resolveEntityId($entity, $entityId));
        $log->setIpAddress($ipAddress ?? $this->requestStack->getCurrentRequest()?->getClientIp());
        $log->setMetadata($metadata);

        $this->entityManager->persist($log);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $log;
    }

    public function logUserLogin(User $user, ?array $metadata = null, bool $flush = true): ActivityLog
    {
        return $this->log($user, self::ACTION_USER_LOGIN, metadata: $metadata, flush: $flush);
    }

    public function logProfileUpdate(User $user, object|string $entity, ?array $metadata = null, bool $flush = true): ActivityLog
    {
        return $this->log($user, self::ACTION_PROFILE_UPDATE, $entity, metadata: $metadata, flush: $flush);
    }

    public function logOfferPublished(User $user, JobOffer $jobOffer, ?array $metadata = null, bool $flush = true): ActivityLog
    {
        return $this->log($user, self::ACTION_OFFER_PUBLISHED, $jobOffer, metadata: $metadata, flush: $flush);
    }

    public function logMatchingCalculated(
        ?User $user,
        object|string|null $entity,
        array $scores,
        ?array $metadata = null,
        bool $flush = true,
    ): ActivityLog {
        return $this->log(
            $user,
            self::ACTION_MATCHING_CALCULATED,
            $entity,
            metadata: array_merge($metadata ?? [], [
                'matching_scores' => $scores,
            ]),
            flush: $flush,
        );
    }

    private function resolveEntityType(object|string|null $entity): ?string
    {
        if (is_object($entity)) {
            return $entity::class;
        }

        if (is_string($entity) && '' !== $entity) {
            return $entity;
        }

        return null;
    }

    private function resolveEntityId(object|string|null $entity, ?int $entityId): ?int
    {
        if (null !== $entityId) {
            return $entityId;
        }

        if (!is_object($entity) || !method_exists($entity, 'getId')) {
            return null;
        }

        $resolvedEntityId = $entity->getId();

        if (is_int($resolvedEntityId)) {
            return $resolvedEntityId;
        }

        if (is_numeric($resolvedEntityId)) {
            return (int) $resolvedEntityId;
        }

        return null;
    }
}
