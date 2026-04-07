<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\DBAL\ParameterType;
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
        $conn = $this->getEntityManager()->getConnection();

        $conditions = ['1=1'];
        $params = [];
        $types = [];

        if (null !== $role && '' !== $role) {
            $conditions[] = 'u.roles::text LIKE :role';
            $params['role'] = '%' . $role . '%';
        }

        if (null !== $status && '' !== $status) {
            $conditions[] = 'u.status = :status';
            $params['status'] = $status;
        }

        if (null !== $search && '' !== $search) {
            $conditions[] = 'LOWER(u.email) LIKE :search';
            $params['search'] = '%' . mb_strtolower($search) . '%';
        }

        $whereClause = implode(' AND ', $conditions);

        $total = (int) $conn->fetchOne(
            sprintf('SELECT COUNT(*) FROM "user" u WHERE %s', $whereClause),
            $params,
            $types
        );

        $offset = max(0, ($page - 1) * $perPage);
        $params['limit'] = $perPage;
        $params['offset'] = $offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $ids = $conn->fetchFirstColumn(
            sprintf("SELECT u.id FROM \"user\" u WHERE %s ORDER BY CASE WHEN u.status = 'pending' THEN 0 ELSE 1 END, u.created_at DESC LIMIT :limit OFFSET :offset", $whereClause),
            $params,
            $types
        );

        if ([] === $ids) {
            return [
                'users' => [],
                'total' => $total,
            ];
        }

        $intIds = array_map(static fn ($id): int => (int) $id, $ids);
        $users = $this->findBy(['id' => $intIds]);

        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $orderedUsers = [];
        foreach ($intIds as $id) {
            if (isset($usersById[$id])) {
                $orderedUsers[] = $usersById[$id];
            }
        }

        return [
            'users' => $orderedUsers,
            'total' => $total,
        ];
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
