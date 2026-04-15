<?php

namespace App\Repository;

use App\Entity\AdminActionLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActionLog>
 */
class AdminActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActionLog::class);
    }

    /**
     * @return AdminActionLog[]
     */
    public function findByAdminUser(User $adminUser, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.adminUser = :adminUser')
            ->setParameter('adminUser', $adminUser)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AdminActionLog[]
     */
    public function findByTargetUser(User $targetUser, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.targetUser = :targetUser')
            ->setParameter('targetUser', $targetUser)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AdminActionLog[]
     */
    public function findModerationHistory(
        ?string $adminEmail = null,
        ?string $targetEmail = null,
        ?string $action = null,
        int $limit = 100,
    ): array {
        $queryBuilder = $this->createQueryBuilder('l')
            ->leftJoin('l.adminUser', 'adminUser')
            ->addSelect('adminUser')
            ->leftJoin('l.targetUser', 'targetUser')
            ->addSelect('targetUser')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $adminEmail && '' !== trim($adminEmail)) {
            $queryBuilder
                ->andWhere('LOWER(adminUser.email) LIKE :adminEmail')
                ->setParameter('adminEmail', '%' . mb_strtolower(trim($adminEmail)) . '%');
        }

        if (null !== $targetEmail && '' !== trim($targetEmail)) {
            $queryBuilder
                ->andWhere('LOWER(targetUser.email) LIKE :targetEmail')
                ->setParameter('targetEmail', '%' . mb_strtolower(trim($targetEmail)) . '%');
        }

        if (null !== $action && '' !== trim($action)) {
            $queryBuilder
                ->andWhere('l.action = :action')
                ->setParameter('action', trim($action));
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
