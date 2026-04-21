<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * @return list<Conversation>
     */
    public function findActiveForApplicant(User $applicantUser): array
    {
        return $this->createQueryBuilder('conversation')
            ->leftJoin('conversation.messages', 'message')
            ->addSelect('message')
            ->andWhere('conversation.applicantUser = :applicantUser')
            ->andWhere('conversation.status = :status')
            ->setParameter('applicantUser', $applicantUser)
            ->setParameter('status', ConversationStatus::OPEN)
            ->orderBy('conversation.updatedAt', 'DESC')
            ->addOrderBy('conversation.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Conversation>
     */
    public function findActiveForRecruiter(User $recruiterUser): array
    {
        return $this->createQueryBuilder('conversation')
            ->leftJoin('conversation.messages', 'message')
            ->addSelect('message')
            ->andWhere('conversation.recruiterUser = :recruiterUser')
            ->andWhere('conversation.status = :status')
            ->setParameter('recruiterUser', $recruiterUser)
            ->setParameter('status', ConversationStatus::OPEN)
            ->orderBy('conversation.updatedAt', 'DESC')
            ->addOrderBy('conversation.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBetweenUsers(User $applicantUser, User $recruiterUser): ?Conversation
    {
        return $this->createQueryBuilder('conversation')
            ->andWhere('conversation.applicantUser = :applicantUser')
            ->andWhere('conversation.recruiterUser = :recruiterUser')
            ->setParameter('applicantUser', $applicantUser)
            ->setParameter('recruiterUser', $recruiterUser)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<User>
     */
    public function findDistinctApplicantsForRecruiter(User $recruiterUser): array
    {
        $users = [];
        $seenIds = [];

        foreach ($this->findActiveForRecruiter($recruiterUser) as $conversation) {
            $applicant = $conversation->getApplicantUser();
            if (!$applicant instanceof User) {
                continue;
            }

            $applicantId = $applicant->getId();
            if (null !== $applicantId && isset($seenIds[$applicantId])) {
                continue;
            }

            if (null !== $applicantId) {
                $seenIds[$applicantId] = true;
            }

            $users[] = $applicant;
        }

        return $users;
    }

    //    /**
    //     * @return Conversation[] Returns an array of Conversation objects
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

    //    public function findOneBySomeField($value): ?Conversation
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
