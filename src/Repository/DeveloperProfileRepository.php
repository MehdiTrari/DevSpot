<?php

namespace App\Repository;

use App\Entity\DeveloperProfile;
use App\Enum\UserStatus;
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

        $ids = $this->createQueryBuilder('d')
            ->select('d.id')
            ->innerJoin('d.user', 'u')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->andWhere('u.status = :activeStatus')
            ->setParameter('isPublic', true)
            ->setParameter('activeStatus', UserStatus::ACTIVE)
            ->orderBy('d.portfolioGeneratedAt', 'DESC')
            ->addOrderBy('d.updatedAt', 'DESC')
            ->setFirstResult(($safePage - 1) * $safeLimit)
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getScalarResult();

        $orderedIds = array_map(static fn (array $row): int => (int) $row['id'], $ids);

        if ([] === $orderedIds) {
            return [];
        }

        $profiles = $this->createQueryBuilder('d')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('profileSkills')
            ->leftJoin('profileSkills.skill', 'skill')->addSelect('skill')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', $orderedIds)
            ->getQuery()
            ->getResult();

        $profilesById = [];
        foreach ($profiles as $profile) {
            $profilesById[$profile->getId()] = $profile;
        }

        $orderedProfiles = [];
        foreach ($orderedIds as $id) {
            if (isset($profilesById[$id])) {
                $orderedProfiles[] = $profilesById[$id];
            }
        }

        return $orderedProfiles;
    }

    public function countPublicGeneratedProfiles(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.user', 'u')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->andWhere('u.status = :activeStatus')
            ->setParameter('isPublic', true)
            ->setParameter('activeStatus', UserStatus::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPublicPortfolioBySlugWithDetails(string $slug): ?DeveloperProfile
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('profileSkills')
            ->leftJoin('profileSkills.skill', 'skill')->addSelect('skill')
            ->leftJoin('d.experiences', 'experiences')->addSelect('experiences')
            ->leftJoin('experiences.technologies', 'technologies')->addSelect('technologies')
            ->leftJoin('d.education', 'education')->addSelect('education')
            ->leftJoin('d.desiredPositions', 'desiredPositions')->addSelect('desiredPositions')
            ->andWhere('d.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestPublicGeneratedProfileUpdate(): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.updatedAt AS updatedAt')
            ->innerJoin('d.user', 'u')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->andWhere('u.status = :activeStatus')
            ->setParameter('isPublic', true)
            ->setParameter('activeStatus', UserStatus::ACTIVE)
            ->orderBy('d.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['updatedAt'] ?? null;
    }

    /**
     * @return DeveloperProfile[]
     */
    public function findAllForMatching(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('profileSkills')
            ->leftJoin('profileSkills.skill', 'skill')->addSelect('skill')
            ->leftJoin('d.experiences', 'experiences')->addSelect('experiences')
            ->leftJoin('experiences.technologies', 'technologies')->addSelect('technologies')
            ->leftJoin('d.desiredPositions', 'desiredPositions')->addSelect('desiredPositions')
            ->getQuery()
            ->getResult();
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
