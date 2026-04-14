<?php

namespace App\Repository;

use App\Entity\FavoriteProfile;
use App\Entity\DeveloperProfile;
use App\Entity\RecruiterProfile;
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

    public function findOneForRecruiterAndDeveloperProfile(RecruiterProfile $recruiterProfile, DeveloperProfile $developerProfile): ?FavoriteProfile
    {
        $recruiterProfileId = $recruiterProfile->getId();
        $developerProfileId = $developerProfile->getId();

        if (null === $recruiterProfileId || null === $developerProfileId) {
            return null;
        }

        return $this->createQueryBuilder('favorite_profile')
            ->andWhere('IDENTITY(favorite_profile.recruiterProfile) = :recruiterProfileId')
            ->andWhere('IDENTITY(favorite_profile.developerProfile) = :developerProfileId')
            ->setParameter('recruiterProfileId', $recruiterProfileId)
            ->setParameter('developerProfileId', $developerProfileId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<FavoriteProfile>
     */
    public function findForRecruiterProfile(RecruiterProfile $recruiterProfile): array
    {
        $recruiterProfileId = $recruiterProfile->getId();
        if (null === $recruiterProfileId) {
            return [];
        }

        return $this->createQueryBuilder('favorite_profile')
            ->leftJoin('favorite_profile.developerProfile', 'developerProfile')->addSelect('developerProfile')
            ->leftJoin('developerProfile.user', 'user')->addSelect('user')
            ->andWhere('IDENTITY(favorite_profile.recruiterProfile) = :recruiterProfileId')
            ->setParameter('recruiterProfileId', $recruiterProfileId)
            ->orderBy('favorite_profile.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<int>
     */
    public function findFavoriteDeveloperProfileIdsForRecruiterProfile(RecruiterProfile $recruiterProfile): array
    {
        $recruiterProfileId = $recruiterProfile->getId();
        if (null === $recruiterProfileId) {
            return [];
        }

        $rows = $this->createQueryBuilder('favorite_profile')
            ->select('IDENTITY(favorite_profile.developerProfile) AS developerProfileId')
            ->andWhere('IDENTITY(favorite_profile.recruiterProfile) = :recruiterProfileId')
            ->setParameter('recruiterProfileId', $recruiterProfileId)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn (array $row): int => (int) $row['developerProfileId'],
            $rows
        ));
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
