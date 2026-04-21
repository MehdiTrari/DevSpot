<?php

namespace App\Form\Model;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminUserMessageData
{
    /**
     * @var list<User>
     */
    #[Assert\Count(min: 1, minMessage: 'Sélectionnez au moins un destinataire.')]
    private array $recipients = [];

    #[Assert\NotBlank(message: 'Merci de renseigner un objet.')]
    #[Assert\Length(max: 255, maxMessage: 'L’objet ne peut pas dépasser {{ limit }} caractères.')]
    private string $title = '';

    #[Assert\NotBlank(message: 'Merci de renseigner un contenu de message.')]
    private string $content = '';

    /**
     * @return list<User>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @param iterable<User> $recipients
     */
    public function setRecipients(iterable $recipients): self
    {
        $resolvedRecipients = [];
        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $resolvedRecipients[] = $recipient;
            }
        }

        $this->recipients = $resolvedRecipients;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title ?? '';

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content ?? '';

        return $this;
    }
}
