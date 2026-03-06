<?php

namespace App\Repository;

use App\Entity\DeveloperProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeveloperProfile>
 */
class DeveloperProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeveloperProfile::class);
    }

    /**
     * @return DeveloperProfile[]
     */
    public function findPublicGeneratedProfilesPaginated(int $page, int $limit): array
    {
        $safePage = max(1, $page);
        $safeLimit = max(1, $limit);

        return $this->createQueryBuilder('d')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->setParameter('isPublic', true)
            ->orderBy('d.portfolioGeneratedAt', 'DESC')
            ->addOrderBy('d.updatedAt', 'DESC')
            ->setFirstResult(($safePage - 1) * $safeLimit)
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getResult();
    }

    public function countPublicGeneratedProfiles(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->setParameter('isPublic', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return DeveloperProfile[] Returns an array of DeveloperProfile objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?DeveloperProfile
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
