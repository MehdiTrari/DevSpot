<?php

namespace App\Repository;

use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * @return Company[]
     */
    public function findAdminCompaniesPaginated(int $page, int $perPage): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        $ids = $this->createQueryBuilder('c')
            ->select('c.id')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(($safePage - 1) * $safePerPage)
            ->setMaxResults($safePerPage)
            ->getQuery()
            ->getScalarResult();

        $orderedIds = array_map(static fn (array $row): int => (int) $row['id'], $ids);

        if ([] === $orderedIds) {
            return [];
        }

        $companies = $this->createQueryBuilder('c')
            ->leftJoin('c.recruiterProfiles', 'recruiterProfiles')->addSelect('recruiterProfiles')
            ->leftJoin('recruiterProfiles.user', 'user')->addSelect('user')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $orderedIds)
            ->getQuery()
            ->getResult();

        $companiesById = [];
        foreach ($companies as $company) {
            $companiesById[$company->getId()] = $company;
        }

        $orderedCompanies = [];
        foreach ($orderedIds as $id) {
            if (isset($companiesById[$id])) {
                $orderedCompanies[] = $companiesById[$id];
            }
        }

        return $orderedCompanies;
    }

    //    /**
     //     * @return Company[] Returns an array of Company objects
     //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Company
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
