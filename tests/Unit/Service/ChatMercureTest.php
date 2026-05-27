<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final class ChatMercureTest extends TestCase
{
    public function testTopicHelpersReturnExpectedTopics(): void
    {
        $service = $this->createService(
            $this->createStub(HubInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(MessageRepository::class),
            false,
        );

        $user = (new User())->setEmail('applicant@example.com')->setRoles(['ROLE_APPLICANT']);
        $conversation = (new Conversation())->setApplicantUser($user);
        $this->setEntityId($user, 12);
        $this->setEntityId($conversation, 34);

        self::assertSame('https://devspot/messages/users/12/conversations', $service->getConversationListTopic($user));
        self::assertSame('https://devspot/messages/users/12/unread-count', $service->getUnreadCountTopic($user));
        self::assertSame('https://devspot/messages/users/12/conversations/34', $service->getConversationTopic($user, $conversation));
        self::assertSame([
            'https://devspot/messages/users/12/conversations',
            'https://devspot/messages/users/12/unread-count',
            'https://devspot/messages/users/12/conversations/34',
        ], $service->getTopicsForUser($user, $conversation));
    }

    public function testGetTopicsForUserOmitsConversationTopicWhenConversationIsNotPersisted(): void
    {
        $service = $this->createService(
            $this->createStub(HubInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(MessageRepository::class),
            true,
        );

        $user = (new User())->setEmail('recruiter@example.com')->setRoles(['ROLE_RECRUITER']);
        $this->setEntityId($user, 7);

        self::assertSame([
            'https://devspot/messages/users/7/conversations',
            'https://devspot/messages/users/7/unread-count',
        ], $service->getTopicsForUser($user, new Conversation()));
        self::assertFalse($service->requiresCredentials());
    }

    public function testPublishConversationReadStateSkipsWhenThereIsNoLastMessage(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository
            ->expects(self::once())
            ->method('findLastInConversation')
            ->willReturn(null);

        $service = $this->createService($hub, $this->createStub(Environment::class), $messageRepository, false);
        $user = (new User())->setEmail('dev@example.com')->setRoles(['ROLE_APPLICANT']);
        $conversation = new Conversation();

        $service->publishConversationReadState($user, $conversation);
    }

    public function testPublishConversationReadStatePublishesExpectedPrivateUpdate(): void
    {
        $publishedUpdates = [];
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update) use (&$publishedUpdates): string {
                $publishedUpdates[] = $update;

                return 'id-1';
            });

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects(self::exactly(2))
            ->method('render')
            ->willReturnCallback(function (string $template, array $context): string {
                if ('broadcast/conversation_row.stream.html.twig' === $template) {
                    self::assertSame('applicant/_conversation_row.html.twig', $context['rowPartial']);
                    self::assertSame(2, $context['row']['unreadCount']);

                    return '[ROW]';
                }

                self::assertSame('broadcast/chat_entry_point.stream.html.twig', $template);
                self::assertSame(5, $context['unreadCount']);

                return '[ENTRY]';
            });

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository
            ->expects(self::once())
            ->method('findLastInConversation')
            ->willReturnCallback(fn (Conversation $conversation): Message => $this->createMessage($conversation));
        $messageRepository
            ->expects(self::once())
            ->method('countUnreadInConversationForUser')
            ->willReturn(2);
        $messageRepository
            ->expects(self::once())
            ->method('countUnreadForUser')
            ->willReturn(5);

        $service = $this->createService($hub, $twig, $messageRepository, false);

        $user = (new User())->setEmail('applicant@example.com')->setRoles(['ROLE_APPLICANT']);
        $recruiter = (new User())->setEmail('recruiter@example.com')->setRoles(['ROLE_RECRUITER']);
        $conversation = (new Conversation())
            ->setApplicantUser($user)
            ->setRecruiterUser($recruiter);
        $this->setEntityId($user, 3);
        $this->setEntityId($conversation, 9);

        $service->publishConversationReadState($user, $conversation);

        self::assertCount(1, $publishedUpdates);
        self::assertSame([
            'https://devspot/messages/users/3/conversations',
            'https://devspot/messages/users/3/unread-count',
            'https://devspot/messages/users/3/conversations/9',
        ], $publishedUpdates[0]->getTopics());
        self::assertSame('[ROW][ENTRY]', $publishedUpdates[0]->getData());
        self::assertTrue($publishedUpdates[0]->isPrivate());
    }

    public function testPublishMessagePublishesOneUpdatePerParticipant(): void
    {
        $publishedUpdates = [];
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::exactly(2))
            ->method('publish')
            ->willReturnCallback(function (Update $update) use (&$publishedUpdates): string {
                $publishedUpdates[] = $update;

                return 'published';
            });

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects(self::exactly(6))
            ->method('render')
            ->willReturnCallback(function (string $template, array $context): string {
                return match ($template) {
                    'broadcast/chat_message.stream.html.twig' => sprintf('[MSG:%s:%s]', $context['messagePartial'], $context['mine'] ? 'mine' : 'other'),
                    'broadcast/conversation_row.stream.html.twig' => sprintf('[ROW:%s]', $context['rowPartial']),
                    'broadcast/chat_entry_point.stream.html.twig' => sprintf('[ENTRY:%d]', $context['unreadCount']),
                    default => throw new \RuntimeException(sprintf('Unexpected template %s', $template)),
                };
            });

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository
            ->expects(self::exactly(2))
            ->method('countUnreadInConversationForUser')
            ->willReturnOnConsecutiveCalls(1, 4);
        $messageRepository
            ->expects(self::exactly(2))
            ->method('countUnreadForUser')
            ->willReturnOnConsecutiveCalls(6, 2);

        $service = $this->createService($hub, $twig, $messageRepository, true);

        $applicant = (new User())->setEmail('applicant@example.com')->setRoles(['ROLE_APPLICANT']);
        $recruiter = (new User())->setEmail('recruiter@example.com')->setRoles(['ROLE_RECRUITER']);
        $conversation = (new Conversation())
            ->setApplicantUser($applicant)
            ->setRecruiterUser($recruiter);
        $message = (new Message())
            ->setConversation($conversation)
            ->setSenderUser($applicant)
            ->setContent('Bonjour');
        $this->setEntityId($applicant, 11);
        $this->setEntityId($recruiter, 22);
        $this->setEntityId($conversation, 99);

        $service->publishMessage($message);

        self::assertCount(2, $publishedUpdates);
        self::assertSame([
            'https://devspot/messages/users/11/conversations',
            'https://devspot/messages/users/11/unread-count',
            'https://devspot/messages/users/11/conversations/99',
        ], $publishedUpdates[0]->getTopics());
        self::assertSame('[MSG:applicant/_chat_message.html.twig:mine][ROW:applicant/_conversation_row.html.twig][ENTRY:6]', $publishedUpdates[0]->getData());
        self::assertFalse($publishedUpdates[0]->isPrivate());

        self::assertSame([
            'https://devspot/messages/users/22/conversations',
            'https://devspot/messages/users/22/unread-count',
            'https://devspot/messages/users/22/conversations/99',
        ], $publishedUpdates[1]->getTopics());
        self::assertSame('[MSG:recruiter/_chat_message.html.twig:other][ROW:recruiter/_conversation_row.html.twig][ENTRY:2]', $publishedUpdates[1]->getData());
        self::assertFalse($publishedUpdates[1]->isPrivate());
    }

    private function createService(HubInterface $hub, Environment $twig, MessageRepository $messageRepository, bool $debug): ChatMercure
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('isDebug')->willReturn($debug);

        return new ChatMercure($hub, $twig, $messageRepository, $kernel);
    }

    private function createMessage(Conversation $conversation): Message
    {
        $message = (new Message())
            ->setConversation($conversation)
            ->setContent('Dernier message');

        $message->setSenderUser($conversation->getApplicantUser() ?? new User());

        return $message;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);

        do {
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($entity, $id);

                return;
            }

            $reflection = $reflection->getParentClass();
        } while (false !== $reflection);

        self::fail('Unable to set entity id via reflection.');
    }
}