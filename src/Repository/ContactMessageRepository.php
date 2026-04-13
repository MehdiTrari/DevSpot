<?php

namespace App\Repository;

use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    public function recruiterHasAlreadyContactedProfile(DeveloperProfile $developerProfile, string $recruiterEmail): bool
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));

        if ('' === $normalizedEmail) {
            return false;
        }

        $result = $this->createQueryBuilder('contact_message')
            ->select('1')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * @return list<ContactMessage>
     */
    public function findByDeveloperProfileOrdered(DeveloperProfile $developerProfile): array
    {
        return $this->createQueryBuilder('contact_message')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->setParameter('developerProfile', $developerProfile)
            ->orderBy('contact_message.createdAt', 'DESC')
            ->addOrderBy('contact_message.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForDeveloperProfile(int $messageId, DeveloperProfile $developerProfile): ?ContactMessage
    {
        return $this->createQueryBuilder('contact_message')
            ->andWhere('contact_message.id = :messageId')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->setParameter('messageId', $messageId)
            ->setParameter('developerProfile', $developerProfile)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForRecruiterAndProfile(DeveloperProfile $developerProfile, string $recruiterEmail): ?ContactMessage
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));

        if ('' === $normalizedEmail) {
            return null;
        }

        return $this->createQueryBuilder('contact_message')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->orderBy('contact_message.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countDistinctProfilesContactedByRecruiterBetween(string $recruiterEmail, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));

        if ('' === $normalizedEmail) {
            return 0;
        }

        return (int) $this->createQueryBuilder('contact_message')
            ->select('COUNT(DISTINCT IDENTITY(contact_message.developerProfile))')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->andWhere('contact_message.createdAt >= :from')
            ->andWhere('contact_message.createdAt < :to')
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return ContactMessage[] Returns an array of ContactMessage objects
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

    //    public function findOneBySomeField($value): ?ContactMessage
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
