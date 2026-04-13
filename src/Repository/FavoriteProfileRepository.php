<?php

namespace App\Repository;

use App\Entity\FavoriteProfile;
use App\Entity\DeveloperProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FavoriteProfile>
 */
class FavoriteProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FavoriteProfile::class);
    }

    /**
     * @return list<FavoriteProfile>
     */
    public function findByDeveloperProfile(DeveloperProfile $developerProfile): array
    {
        $developerProfileId = $developerProfile->getId();
        if (null === $developerProfileId) {
            return [];
        }

        return $this->createQueryBuilder('favorite_profile')
            ->andWhere('IDENTITY(favorite_profile.developerProfile) = :developerProfileId')
            ->setParameter('developerProfileId', $developerProfileId)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return FavoriteProfile[] Returns an array of FavoriteProfile objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('f.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?FavoriteProfile
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
