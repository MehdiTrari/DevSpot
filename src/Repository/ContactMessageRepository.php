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
