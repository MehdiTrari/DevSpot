<?php

namespace App\Repository;

use App\Entity\AdminActionLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * @return list<AdminActionLog>
     */
    public function findByAdmin(User $adminUser, int $limit = 100): array
    {
        return $this->createQueryBuilder('log')
            ->andWhere('log.adminUser = :adminUser')
            ->setParameter('adminUser', $adminUser)
            ->orderBy('log.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AdminActionLog>
     */
    public function findByTarget(User $targetUser, int $limit = 100): array
    {
        return $this->createQueryBuilder('log')
            ->andWhere('log.targetUser = :targetUser')
            ->setParameter('targetUser', $targetUser)
            ->orderBy('log.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AdminActionLog>
     */
    public function findForHistory(?User $adminUser = null, ?User $targetUser = null, int $page = 1, int $perPage = 20): array
    {
        $queryBuilder = $this->createQueryBuilder('log')
            ->addSelect('adminUser', 'targetUser')
            ->leftJoin('log.adminUser', 'adminUser')
            ->leftJoin('log.targetUser', 'targetUser')
            ->orderBy('log.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        $this->applyHistoryFilters($queryBuilder, $adminUser, $targetUser);

        return $queryBuilder->getQuery()->getResult();
    }

    public function countForHistory(?User $adminUser = null, ?User $targetUser = null): int
    {
        $queryBuilder = $this->createQueryBuilder('log')
            ->select('COUNT(log.id)');

        $this->applyHistoryFilters($queryBuilder, $adminUser, $targetUser);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    private function applyHistoryFilters(QueryBuilder $queryBuilder, ?User $adminUser, ?User $targetUser): void
    {
        if ($adminUser instanceof User) {
            $queryBuilder
                ->andWhere('log.adminUser = :adminUser')
                ->setParameter('adminUser', $adminUser);
        }

        if ($targetUser instanceof User) {
            $queryBuilder
                ->andWhere('log.targetUser = :targetUser')
                ->setParameter('targetUser', $targetUser);
        }
    }

    //    /**
    //     * @return AdminActionLog[] Returns an array of AdminActionLog objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?AdminActionLog
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
