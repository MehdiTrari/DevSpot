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
