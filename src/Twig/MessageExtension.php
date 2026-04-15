<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class MessageExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ChatMercure $chatMercure,
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('chat_unread_count', [$this, 'chatUnreadCount']),
            new TwigFunction('chat_unread_mercure_topic', [$this, 'chatUnreadMercureTopic']),
            new TwigFunction('chat_mercure_needs_credentials', [$this, 'chatMercureNeedsCredentials']),
        ];
    }

    public function chatUnreadCount(?User $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        return $this->messageRepository->countUnreadForUser($user);
    }

    public function chatUnreadMercureTopic(?User $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        return $this->chatMercure->getUnreadCountTopic($user);
    }

    public function chatMercureNeedsCredentials(): bool
    {
        return $this->chatMercure->requiresCredentials();
    }
}
