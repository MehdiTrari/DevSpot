<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @return array{users: User[], total: int}
     */
    public function findAdminUsersPaginated(?string $role, ?string $status, ?string $search, int $page, int $perPage): array
    {
        $queryBuilder = $this->createQueryBuilder('u')
            ->addSelect('CASE WHEN u.status = :pendingStatus THEN 0 ELSE 1 END AS HIDDEN pendingSort')
            ->setParameter('pendingStatus', UserStatus::PENDING)
            ->orderBy('pendingSort', 'ASC')
            ->addOrderBy('u.createdAt', 'DESC');

        if (null !== $status && '' !== $status) {
            $queryBuilder
                ->andWhere('u.status = :status')
                ->setParameter('status', UserStatus::from($status));
        }

        if (null !== $search && '' !== $search) {
            $queryBuilder
                ->leftJoin('u.developerProfile', 'developerProfile')
                ->leftJoin('u.recruiterProfile', 'recruiterProfile')
                ->andWhere($queryBuilder->expr()->orX(
                    'LOWER(u.email) LIKE :search',
                    'LOWER(developerProfile.firstName) LIKE :search',
                    'LOWER(developerProfile.lastName) LIKE :search',
                    'LOWER(recruiterProfile.firstName) LIKE :search',
                    'LOWER(recruiterProfile.lastName) LIKE :search'
                ))
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        /** @var User[] $users */
        $users = $queryBuilder->getQuery()->getResult();

        if (null !== $role && '' !== $role) {
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => in_array($role, $user->getRoles(), true)
            ));
        }

        $total = count($users);
        $offset = max(0, ($page - 1) * $perPage);
        $orderedUsers = array_slice($users, $offset, $perPage);

        return [
            'users' => $orderedUsers,
            'total' => $total,
        ];
    }

    /**
     * @return User[]
     */
    public function findAdmins(): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn (User $user): bool => in_array('ROLE_ADMIN', $user->getRoles(), true)
        ));
    }

    public function countByRole(string $role): int
    {
        return count(array_filter(
            $this->findAll(),
            static fn (User $user): bool => in_array($role, $user->getRoles(), true)
        ));
    }

    /**
     * @return User[]
     */
    public function findPendingOlderThan(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :status')
            ->andWhere('u.createdAt < :cutoff')
            ->setParameter('status', UserStatus::PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
