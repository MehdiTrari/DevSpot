<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findForUserOrdered(User $user, ?bool $isRead = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC');

        if (null !== $isRead) {
            $qb
                ->andWhere('n.isRead = :isRead')
                ->setParameter('isRead', $isRead);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Notification[]
     */
    public function findForUserOrderedPaginated(User $user, ?bool $isRead, int $page, int $perPage): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($safePage - 1) * $safePerPage)
            ->setMaxResults($safePerPage);

        if (null !== $isRead) {
            $qb
                ->andWhere('n.isRead = :isRead')
                ->setParameter('isRead', $isRead);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForUser(User $user, ?bool $isRead = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user);

        if (null !== $isRead) {
            $qb
                ->andWhere('n.isRead = :isRead')
                ->setParameter('isRead', $isRead);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllReadForUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':isRead')
            ->andWhere('n.user = :user')
            ->andWhere('n.isRead = :currentReadState')
            ->setParameter('isRead', true)
            ->setParameter('user', $user)
            ->setParameter('currentReadState', false)
            ->getQuery()
            ->execute();
    }

    public function existsForUserTypeAndLink(User $user, NotificationType $type, ?string $link): bool
    {
        return null !== $this->createQueryBuilder('n')
            ->select('n.id')
            ->andWhere('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.link = :link')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('link', $link)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return Notification[] Returns an array of Notification objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('n.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Notification
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
