<?php

namespace App\Entity;

use App\Repository\FavoriteProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FavoriteProfileRepository::class)]
#[ORM\UniqueConstraint(name: 'favorite_profile_unique_pair', columns: ['recruiter_profile_id', 'developer_profile_id'])]
class FavoriteProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'favoriteProfiles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?RecruiterProfile $recruiterProfile = null;

    #[ORM\ManyToOne(inversedBy: 'favoriteProfiles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DeveloperProfile $developerProfile = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecruiterProfile(): ?RecruiterProfile
    {
        return $this->recruiterProfile;
    }

    public function setRecruiterProfile(RecruiterProfile $recruiterProfile): static
    {
        $this->recruiterProfile = $recruiterProfile;

        return $this;
    }

    public function getDeveloperProfile(): ?DeveloperProfile
    {
        return $this->developerProfile;
    }

    public function setDeveloperProfile(DeveloperProfile $developerProfile): static
    {
        $this->developerProfile = $developerProfile;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
