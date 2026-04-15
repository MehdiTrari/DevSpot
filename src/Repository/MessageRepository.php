<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * @return list<Message>
     */
    public function findByConversationOrdered(Conversation $conversation): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('message.createdAt', 'ASC')
            ->addOrderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function markConversationAsReadForUser(Conversation $conversation, User $viewerUser): void
    {
        $this->createQueryBuilder('message')
            ->update()
            ->set('message.isRead', ':isRead')
            ->andWhere('message.conversation = :conversation')
            ->andWhere('message.senderUser != :viewerUser')
            ->andWhere('message.isRead = :unread')
            ->setParameter('isRead', true)
            ->setParameter('unread', false)
            ->setParameter('conversation', $conversation)
            ->setParameter('viewerUser', $viewerUser)
            ->getQuery()
            ->execute();
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.senderUser != :user')
            ->andWhere('message.isRead = :isRead')
            ->andWhere('message.conversation IN (
                SELECT conversation.id FROM App\\Entity\\Conversation conversation
                WHERE conversation.applicantUser = :user OR conversation.recruiterUser = :user
            )')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadInConversationForUser(Conversation $conversation, User $user): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.conversation = :conversation')
            ->andWhere('message.senderUser != :user')
            ->andWhere('message.isRead = :isRead')
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLastInConversation(Conversation $conversation): ?Message
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('message.createdAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Message>
     */
    public function findByConversationAfterIdOrdered(Conversation $conversation, int $sinceId): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')
            ->andWhere('message.id > :sinceId')
            ->setParameter('conversation', $conversation)
            ->setParameter('sinceId', $sinceId)
            ->orderBy('message.createdAt', 'ASC')
            ->addOrderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Message[] Returns an array of Message objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('m.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Message
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
