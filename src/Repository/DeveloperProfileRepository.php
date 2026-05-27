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
    public function findAdminProfilesPaginated(int $page, int $perPage): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        $ids = $this->createQueryBuilder('d')
            ->select('d.id')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($safePage - 1) * $safePerPage)
            ->setMaxResults($safePerPage)
            ->getQuery()
            ->getScalarResult();

        $orderedIds = array_map(static fn (array $row): int => (int) $row['id'], $ids);

        if ([] === $orderedIds) {
            return [];
        }

        $profiles = $this->createQueryBuilder('d')
            ->leftJoin('d.user', 'u')->addSelect('u')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('profileSkills')
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

    /**
     * @return DeveloperProfile[]
     */
    public function findVisibleProfilesForMatching(?string $emailSuffix = null): array
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('partial d.{id, firstName, lastName, headline, bio, experienceLevel, yearsExperience, isPublic, slug, githubUrl, linkedinUrl, portfolioUrl, portfolioGeneratedAt, updatedAt}')
            ->innerJoin('d.user', 'u')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('partial profileSkills.{id, level, years}')
            ->leftJoin('profileSkills.skill', 'skill')->addSelect('partial skill.{id, name, category}')
            ->leftJoin('d.experiences', 'experiences')->addSelect('partial experiences.{id, title, startDate, endDate, isCurrent, description}')
            ->leftJoin('experiences.technologies', 'technologies')->addSelect('partial technologies.{id, name}')
            ->leftJoin('d.education', 'education')->addSelect('partial education.{id, degree, field, startDate, endDate, description}')
            ->leftJoin('d.desiredPositions', 'desiredPositions')->addSelect('partial desiredPositions.{id, name}')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('d.portfolioGeneratedAt IS NOT NULL')
            ->andWhere('u.status = :activeStatus')
            ->setParameter('isPublic', true)
            ->setParameter('activeStatus', UserStatus::ACTIVE)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        if (null !== $emailSuffix && '' !== trim($emailSuffix)) {
            $queryBuilder
                ->andWhere('u.email LIKE :emailSuffix')
                ->setParameter('emailSuffix', '%' . trim($emailSuffix));
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param list<int> $profileIds
     *
     * @return array<int, array{embedding: array<mixed>, dimension: int, textHash: string}>
     */
    public function findStoredMatchingEmbeddingsByProfileIds(array $profileIds): array
    {
        $profileIds = array_values(array_unique(array_filter(
            array_map(static fn (int|string $id): int => (int) $id, $profileIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ([] === $profileIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('d.id AS id', 'd.matchingEmbedding AS embedding', 'd.matchingEmbeddingDimension AS dimension', 'd.matchingEmbeddingTextHash AS textHash')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.matchingEmbedding IS NOT NULL')
            ->andWhere('d.matchingEmbeddingDimension IS NOT NULL')
            ->andWhere('d.matchingEmbeddingTextHash IS NOT NULL')
            ->setParameter('ids', $profileIds)
            ->getQuery()
            ->getArrayResult();

        $embeddings = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $embedding = $row['embedding'] ?? null;
            $dimension = $row['dimension'] ?? null;
            $textHash = $row['textHash'] ?? null;

            if ($id <= 0 || !is_array($embedding) || !is_numeric($dimension) || !is_string($textHash) || '' === trim($textHash)) {
                continue;
            }

            $embeddings[$id] = [
                'embedding' => $embedding,
                'dimension' => (int) $dimension,
                'textHash' => $textHash,
            ];
        }

        return $embeddings;
    }

    /**
     * @return DeveloperProfile[]
     */
    public function findPublicProfilesForMatchingEmbeddings(?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->innerJoin('d.user', 'u')->addSelect('u')
            ->leftJoin('d.profileSkills', 'profileSkills')->addSelect('profileSkills')
            ->leftJoin('profileSkills.skill', 'skill')->addSelect('skill')
            ->leftJoin('d.experiences', 'experiences')->addSelect('experiences')
            ->leftJoin('experiences.technologies', 'technologies')->addSelect('technologies')
            ->leftJoin('d.education', 'education')->addSelect('education')
            ->leftJoin('d.desiredPositions', 'desiredPositions')->addSelect('desiredPositions')
            ->andWhere('d.isPublic = :isPublic')
            ->andWhere('u.status = :activeStatus')
            ->setParameter('isPublic', true)
            ->setParameter('activeStatus', UserStatus::ACTIVE)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        if (null !== $limit) {
            $queryBuilder->setMaxResults(max(1, $limit));
        }

        return $queryBuilder->getQuery()->getResult();
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
