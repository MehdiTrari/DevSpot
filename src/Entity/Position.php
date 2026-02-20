<?php

namespace App\Entity;

use App\Repository\PositionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $name = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, DeveloperProfile>
     */
    #[ORM\ManyToMany(targetEntity: DeveloperProfile::class, mappedBy: 'desiredPositions')]
    private Collection $developerProfiles;

    public function __construct()
    {
        $this->developerProfiles = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, DeveloperProfile>
     */
    public function getDeveloperProfiles(): Collection
    {
        return $this->developerProfiles;
    }

    public function addDeveloperProfile(DeveloperProfile $developerProfile): static
    {
        if (!$this->developerProfiles->contains($developerProfile)) {
            $this->developerProfiles->add($developerProfile);
            $developerProfile->addDesiredPosition($this);
        }

        return $this;
    }

    public function removeDeveloperProfile(DeveloperProfile $developerProfile): static
    {
        if ($this->developerProfiles->removeElement($developerProfile)) {
            $developerProfile->removeDesiredPosition($this);
        }

        return $this;
    }
}
