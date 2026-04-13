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

    /**
     * @return list<array{recruiterEmail: string, recruiterName: string, lastMessage: ContactMessage, unreadCount: int}>
     */
    public function findActiveConversationsForApplicant(DeveloperProfile $developerProfile): array
    {
        $messages = $this->createQueryBuilder('contact_message')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->setParameter('developerProfile', $developerProfile)
            ->orderBy('contact_message.createdAt', 'DESC')
            ->addOrderBy('contact_message.id', 'DESC')
            ->getQuery()
            ->getResult();

        $conversations = [];
        foreach ($messages as $message) {
            if (!$message instanceof ContactMessage) {
                continue;
            }

            $email = trim((string) $message->getRecruiterEmail());
            if ('' === $email) {
                continue;
            }

            $key = mb_strtolower($email);
            if (!isset($conversations[$key])) {
                $conversations[$key] = [
                    'recruiterEmail' => $email,
                    'recruiterName' => (string) ($message->getRecruiterName() ?? ''),
                    'lastMessage' => $message,
                    'unreadCount' => 0,
                ];
            }

            if (!$message->isRead()) {
                $conversations[$key]['unreadCount']++;
            }
        }

        return array_values($conversations);
    }

    /**
     * @return list<array{developerProfile: DeveloperProfile, lastMessage: ContactMessage, unreadCount: int}>
     */
    public function findActiveConversationsForRecruiter(string $recruiterEmail): array
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));
        if ('' === $normalizedEmail) {
            return [];
        }

        $messages = $this->createQueryBuilder('contact_message')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->orderBy('contact_message.createdAt', 'DESC')
            ->addOrderBy('contact_message.id', 'DESC')
            ->getQuery()
            ->getResult();

        $conversations = [];
        foreach ($messages as $message) {
            if (!$message instanceof ContactMessage) {
                continue;
            }

            $profile = $message->getDeveloperProfile();
            if (!$profile instanceof DeveloperProfile || null === $profile->getId()) {
                continue;
            }

            $key = (string) $profile->getId();
            if (!isset($conversations[$key])) {
                $conversations[$key] = [
                    'developerProfile' => $profile,
                    'lastMessage' => $message,
                    'unreadCount' => 0,
                ];
            }

            if (!$message->isRead()) {
                $conversations[$key]['unreadCount']++;
            }
        }

        return array_values($conversations);
    }

    /**
     * @return list<ContactMessage>
     */
    public function findConversationForApplicant(DeveloperProfile $developerProfile, string $recruiterEmail): array
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));
        if ('' === $normalizedEmail) {
            return [];
        }

        return $this->createQueryBuilder('contact_message')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->orderBy('contact_message.createdAt', 'ASC')
            ->addOrderBy('contact_message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ContactMessage>
     */
    public function findConversationForRecruiter(DeveloperProfile $developerProfile, string $recruiterEmail): array
    {
        return $this->findConversationForApplicant($developerProfile, $recruiterEmail);
    }

    public function hasRecruiterInitiatedConversation(DeveloperProfile $developerProfile, string $recruiterEmail): bool
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

    public function markConversationAsReadByApplicant(DeveloperProfile $developerProfile, string $recruiterEmail): void
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));
        if ('' === $normalizedEmail) {
            return;
        }

        $this->createQueryBuilder('contact_message')
            ->update()
            ->set('contact_message.isRead', ':isRead')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->andWhere('contact_message.isRead = :unread')
            ->setParameter('isRead', true)
            ->setParameter('unread', false)
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->getQuery()
            ->execute();
    }

    public function markConversationAsReadByRecruiter(DeveloperProfile $developerProfile, string $recruiterEmail): void
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));
        if ('' === $normalizedEmail) {
            return;
        }

        $this->createQueryBuilder('contact_message')
            ->update()
            ->set('contact_message.isRead', ':isRead')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->andWhere('contact_message.isRead = :unread')
            ->setParameter('isRead', true)
            ->setParameter('unread', false)
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->getQuery()
            ->execute();
    }

    public function countUnreadForApplicant(DeveloperProfile $developerProfile): int
    {
        return (int) $this->createQueryBuilder('contact_message')
            ->select('COUNT(contact_message.id)')
            ->andWhere('contact_message.developerProfile = :developerProfile')
            ->andWhere('contact_message.isRead = :isRead')
            ->setParameter('developerProfile', $developerProfile)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadForRecruiterEmail(string $recruiterEmail): int
    {
        $normalizedEmail = mb_strtolower(trim($recruiterEmail));
        if ('' === $normalizedEmail) {
            return 0;
        }

        return (int) $this->createQueryBuilder('contact_message')
            ->select('COUNT(contact_message.id)')
            ->andWhere('LOWER(contact_message.recruiterEmail) = :recruiterEmail')
            ->andWhere('contact_message.isRead = :isRead')
            ->setParameter('recruiterEmail', $normalizedEmail)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
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
