<?php

namespace App\Repository;

use App\Entity\SupportRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportRequest>
 */
final class SupportRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportRequest::class);
    }

    /**
     * @return list<SupportRequest>
     */
    public function findAdminSupportRequestsPaginated(int $page, int $perPage): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        return $this->createQueryBuilder('support_request')
            ->orderBy('support_request.createdAt', 'DESC')
            ->addOrderBy('support_request.id', 'DESC')
            ->setFirstResult(($safePage - 1) * $safePerPage)
            ->setMaxResults($safePerPage)
            ->getQuery()
            ->getResult();
    }
}
