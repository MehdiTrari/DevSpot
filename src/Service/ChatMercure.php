<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final class ChatMercure
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly MessageRepository $messageRepository,
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getTopicsForUser(User $user, ?Conversation $conversation = null): array
    {
        $topics = [
            $this->getConversationListTopic($user),
            $this->getUnreadCountTopic($user),
        ];

        if ($conversation instanceof Conversation && null !== $conversation->getId()) {
            $topics[] = $this->getConversationTopic($user, $conversation);
        }

        return $topics;
    }

    public function publishMessage(Message $message): void
    {
        $conversation = $message->getConversation();
        if (!$conversation instanceof Conversation) {
            return;
        }

        $applicantUser = $conversation->getApplicantUser();
        if ($applicantUser instanceof User) {
            $this->publishUpdateForUser($applicantUser, $message, 'applicant');
        }

        $recruiterUser = $conversation->getRecruiterUser();
        if ($recruiterUser instanceof User) {
            $this->publishUpdateForUser($recruiterUser, $message, 'recruiter');
        }
    }

    public function publishConversationReadState(User $user, Conversation $conversation): void
    {
        $lastMessage = $this->messageRepository->findLastInConversation($conversation);
        if (!$lastMessage instanceof Message) {
            return;
        }

        $payload = $this->renderConversationMetaStreams($user, $conversation, $lastMessage, $this->resolveViewNamespace($user));
        if ('' === $payload) {
            return;
        }

        $this->hub->publish(new Update(
            $this->getTopicsForUser($user, $conversation),
            $payload,
            $this->requiresCredentials()
        ));
    }

    public function getConversationTopic(User $user, Conversation $conversation): string
    {
        return sprintf(
            'https://devspot/messages/users/%d/conversations/%d',
            (int) $user->getId(),
            (int) $conversation->getId()
        );
    }

    public function getConversationListTopic(User $user): string
    {
        return sprintf('https://devspot/messages/users/%d/conversations', (int) $user->getId());
    }

    public function getUnreadCountTopic(User $user): string
    {
        return sprintf('https://devspot/messages/users/%d/unread-count', (int) $user->getId());
    }

    public function requiresCredentials(): bool
    {
        return !$this->kernel->isDebug();
    }

    private function publishUpdateForUser(User $user, Message $message, string $viewNamespace): void
    {
        $conversation = $message->getConversation();
        if (!$conversation instanceof Conversation) {
            return;
        }

        $payload = $this->renderMessageStreams($user, $message, $viewNamespace);

        $this->hub->publish(new Update(
            $this->getTopicsForUser($user, $conversation),
            $payload,
            $this->requiresCredentials()
        ));
    }

    private function renderMessageStreams(User $user, Message $message, string $viewNamespace): string
    {
        $conversation = $message->getConversation();
        if (!$conversation instanceof Conversation) {
            return '';
        }

        $payload = $this->twig->render('broadcast/chat_message.stream.html.twig', [
            'message' => $message,
            'mine' => $message->getSenderUser()?->getId() === $user->getId(),
            'messagePartial' => sprintf('%s/_chat_message.html.twig', $viewNamespace),
        ]);

        return $payload.$this->renderConversationMetaStreams($user, $conversation, $message, $viewNamespace);
    }

    private function renderConversationMetaStreams(User $user, Conversation $conversation, Message $lastMessage, string $viewNamespace): string
    {
        return $this->twig->render('broadcast/conversation_row.stream.html.twig', [
            'row' => $this->buildConversationRow($user, $conversation, $lastMessage),
            'rowPartial' => sprintf('%s/_conversation_row.html.twig', $viewNamespace),
        ]).$this->twig->render('broadcast/chat_entry_point.stream.html.twig', [
            'user' => $user,
            'unreadCount' => $this->messageRepository->countUnreadForUser($user),
        ]);
    }

    /**
     * @return array{conversation: Conversation, recruiterUser?: ?User, developerProfile?: mixed, lastMessage: Message, unreadCount: int}
     */
    private function buildConversationRow(User $user, Conversation $conversation, Message $lastMessage): array
    {
        $row = [
            'conversation' => $conversation,
            'lastMessage' => $lastMessage,
            'unreadCount' => $this->messageRepository->countUnreadInConversationForUser($conversation, $user),
        ];

        if (in_array('ROLE_APPLICANT', $user->getRoles(), true)) {
            $row['recruiterUser'] = $conversation->getRecruiterUser();

            return $row;
        }

        $row['developerProfile'] = $conversation->getApplicantUser()?->getDeveloperProfile();

        return $row;
    }

    private function resolveViewNamespace(User $user): string
    {
        if (in_array('ROLE_APPLICANT', $user->getRoles(), true)) {
            return 'applicant';
        }

        return 'recruiter';
    }
}
