<?php

namespace App\EventSubscriber;

use App\Entity\AdminActionLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final class AdminActionLogImmutabilitySubscriber
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AdminActionLog) {
                throw new \LogicException('Les journaux de moderation ne peuvent pas etre supprimes.');
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof AdminActionLog) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            $changedFields = array_keys($changeSet);
            $allowedAnonymizationFields = ['adminUser', 'targetUser'];

            if ([] === array_diff($changedFields, $allowedAnonymizationFields) && $this->onlyClearsUserLinks($changeSet)) {
                continue;
            }

            throw new \LogicException('Les journaux de moderation sont immuables apres creation.');
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function onlyClearsUserLinks(array $changeSet): bool
    {
        foreach ($changeSet as [$previousValue, $newValue]) {
            if (null === $previousValue || null !== $newValue) {
                return false;
            }
        }

        return true;
    }
}
