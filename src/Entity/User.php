<?php

namespace App\Entity;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?DeveloperProfile $developerProfile = null;

    #[ORM\Column]
    private ?bool $isVerified = false;

    #[ORM\Column(enumType: UserStatus::class)]
    private ?UserStatus $status = UserStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, ActivityLog>
     */
    #[ORM\OneToMany(targetEntity: ActivityLog::class, mappedBy: 'user')]
    private Collection $activityLogs;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?RecruiterProfile $recruiterProfile = null;

    /**
     * @var Collection<int, AdminActionLog>
     */
    #[ORM\OneToMany(targetEntity: AdminActionLog::class, mappedBy: 'adminUser')]
    private Collection $adminActionLogs;

    /**
     * @var Collection<int, AdminActionLog>
     */
    #[ORM\OneToMany(targetEntity: AdminActionLog::class, mappedBy: 'targetUser')]
    private Collection $targetedAdminActionLogs;

    /**
     * @var Collection<int, Notification>
     */
    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $notifications;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'applicantUser')]
    private Collection $conversations;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'recruiterUser')]
    private Collection $conversationsRecruiter;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'senderUser')]
    private Collection $messages;

    public function __construct()
    {
        $this->activityLogs = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->adminActionLogs = new ArrayCollection();
        $this->targetedAdminActionLogs = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->conversationsRecruiter = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function getDeveloperProfile(): ?DeveloperProfile
    {
        return $this->developerProfile;
    }

    public function setDeveloperProfile(DeveloperProfile $developerProfile): static
    {
        // set the owning side of the relation if necessary
        if ($developerProfile->getUser() !== $this) {
            $developerProfile->setUser($this);
        }

        $this->developerProfile = $developerProfile;

        return $this;
    }

    public function isVerified(): ?bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getStatus(): ?UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function getActivityLogs(): Collection
    {
        return $this->activityLogs;
    }

    public function addActivityLog(ActivityLog $activityLog): static
    {
        if (!$this->activityLogs->contains($activityLog)) {
            $this->activityLogs->add($activityLog);
            $activityLog->setUser($this);
        }

        return $this;
    }

    public function removeActivityLog(ActivityLog $activityLog): static
    {
        if ($this->activityLogs->removeElement($activityLog)) {
            // set the owning side to null (unless already changed)
            if ($activityLog->getUser() === $this) {
                $activityLog->setUser(null);
            }
        }

        return $this;
    }

    public function getRecruiterProfile(): ?RecruiterProfile
    {
        return $this->recruiterProfile;
    }

    public function setRecruiterProfile(RecruiterProfile $recruiterProfile): static
    {
        // set the owning side of the relation if necessary
        if ($recruiterProfile->getUser() !== $this) {
            $recruiterProfile->setUser($this);
        }

        $this->recruiterProfile = $recruiterProfile;

        return $this;
    }

    /**
     * @return Collection<int, AdminActionLog>
     */
    public function getAdminActionLogs(): Collection
    {
        return $this->adminActionLogs;
    }

    public function addAdminActionLog(AdminActionLog $adminActionLog): static
    {
        if (!$this->adminActionLogs->contains($adminActionLog)) {
            $this->adminActionLogs->add($adminActionLog);
            $adminActionLog->setAdminUser($this);
        }

        return $this;
    }

    public function removeAdminActionLog(AdminActionLog $adminActionLog): static
    {
        if ($this->adminActionLogs->removeElement($adminActionLog)) {
            // set the owning side to null (unless already changed)
            if ($adminActionLog->getAdminUser() === $this) {
                $adminActionLog->setAdminUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AdminActionLog>
     */
    public function getTargetedAdminActionLogs(): Collection
    {
        return $this->targetedAdminActionLogs;
    }

    public function addTargetedAdminActionLog(AdminActionLog $adminActionLog): static
    {
        if (!$this->targetedAdminActionLogs->contains($adminActionLog)) {
            $this->targetedAdminActionLogs->add($adminActionLog);
            $adminActionLog->setTargetUser($this);
        }

        return $this;
    }

    public function removeTargetedAdminActionLog(AdminActionLog $adminActionLog): static
    {
        if ($this->targetedAdminActionLogs->removeElement($adminActionLog)) {
            // set the owning side to null (unless already changed)
            if ($adminActionLog->getTargetUser() === $this) {
                $adminActionLog->setTargetUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function addNotification(Notification $notification): static
    {
        if (!$this->notifications->contains($notification)) {
            $this->notifications->add($notification);
            $notification->setUserTarget($this);
        }

        return $this;
    }

    public function removeNotification(Notification $notification): static
    {
        if ($this->notifications->removeElement($notification)) {
            // set the owning side to null (unless already changed)
            if ($notification->getUserTarget() === $this) {
                $notification->setUserTarget(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getConversations(): Collection
    {
        return $this->conversations;
    }

    public function addConversation(Conversation $conversation): static
    {
        if (!$this->conversations->contains($conversation)) {
            $this->conversations->add($conversation);
            $conversation->setApplicantUser($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation)) {
            // set the owning side to null (unless already changed)
            if ($conversation->getApplicantUser() === $this) {
                $conversation->setApplicantUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getConversationsRecruiter(): Collection
    {
        return $this->conversationsRecruiter;
    }

    public function addConversationsRecruiter(Conversation $conversationsRecruiter): static
    {
        if (!$this->conversationsRecruiter->contains($conversationsRecruiter)) {
            $this->conversationsRecruiter->add($conversationsRecruiter);
            $conversationsRecruiter->setRecruiterUser($this);
        }

        return $this;
    }

    public function removeConversationsRecruiter(Conversation $conversationsRecruiter): static
    {
        if ($this->conversationsRecruiter->removeElement($conversationsRecruiter)) {
            // set the owning side to null (unless already changed)
            if ($conversationsRecruiter->getRecruiterUser() === $this) {
                $conversationsRecruiter->setRecruiterUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setSenderUser($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getSenderUser() === $this) {
                $message->setSenderUser(null);
            }
        }

        return $this;
    }
}
