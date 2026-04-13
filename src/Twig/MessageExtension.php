<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\MessageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class MessageExtension extends AbstractExtension
{
    public function __construct(private readonly MessageRepository $messageRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('chat_unread_count', [$this, 'chatUnreadCount']),
        ];
    }

    public function chatUnreadCount(?User $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        return $this->messageRepository->countUnreadForUser($user);
    }
}
