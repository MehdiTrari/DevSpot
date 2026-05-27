<?php

namespace App\Entity;

use App\Enum\SkillLevel;
use App\Repository\ProfileSkillRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProfileSkillRepository::class)]
#[ORM\Table(
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'profile_skill_unique',
            columns: ['developer_profile_id', 'skill_id']
        ),
    ]
)]
class ProfileSkill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'profileSkills')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DeveloperProfile $developerProfile = null;

    #[ORM\ManyToOne(inversedBy: 'profileSkills')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'La compétence est obligatoire.')]
    private ?Skill $skill = null;

    #[ORM\Column(nullable: true, enumType: SkillLevel::class)]
    private ?SkillLevel $level = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le nombre d\'années doit être positif ou nul.')]
    private ?int $years = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeveloperProfile(): ?DeveloperProfile
    {
        return $this->developerProfile;
    }

    public function setDeveloperProfile(?DeveloperProfile $developerProfile): static
    {
        $this->developerProfile = $developerProfile;

        return $this;
    }

    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    public function setSkill(?Skill $skill): static
    {
        $this->skill = $skill;

        return $this;
    }

    public function getLevel(): ?SkillLevel
    {
        return $this->level;
    }

    public function setLevel(?SkillLevel $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getYears(): ?int
    {
        return $this->years;
    }

    public function setYears(?int $years): static
    {
        $this->years = $years;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
