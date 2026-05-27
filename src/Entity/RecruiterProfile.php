<?php

namespace App\Entity;

use App\Repository\RecruiterProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecruiterProfileRepository::class)]
class RecruiterProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    private ?string $lastName = null;

    #[ORM\Column(length: 255)]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToOne(inversedBy: 'recruiterProfile', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * @var Collection<int, JobOffer>
     */
    #[ORM\OneToMany(targetEntity: JobOffer::class, mappedBy: 'recruiterProfile', orphanRemoval: true)]
    private Collection $jobOffers;

    /**
     * @var Collection<int, FavoriteProfile>
     */
    #[ORM\OneToMany(targetEntity: FavoriteProfile::class, mappedBy: 'recruiterProfile', orphanRemoval: true)]
    private Collection $favoriteProfiles;

    #[ORM\ManyToOne(inversedBy: 'recruiterProfiles')]
    private ?Company $company = null;

    public function __construct()
    {
        $this->jobOffers = new ArrayCollection();
        $this->favoriteProfiles = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getWorkEmail(): ?string
    {
        return $this->workEmail;
    }

    public function setWorkEmail(?string $workEmail): static
    {
        $this->workEmail = $workEmail;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, JobOffer>
     */
    public function getJobOffers(): Collection
    {
        return $this->jobOffers;
    }

    public function addJobOffer(JobOffer $jobOffer): static
    {
        if (!$this->jobOffers->contains($jobOffer)) {
            $this->jobOffers->add($jobOffer);
            $jobOffer->setRecruiterProfile($this);
        }

        return $this;
    }

    public function removeJobOffer(JobOffer $jobOffer): static
    {
        if ($this->jobOffers->removeElement($jobOffer)) {
            // set the owning side to null (unless already changed)
            if ($jobOffer->getRecruiterProfile() === $this) {
                $jobOffer->setRecruiterProfile(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FavoriteProfile>
     */
    public function getFavoriteProfiles(): Collection
    {
        return $this->favoriteProfiles;
    }

    public function addFavoriteProfile(FavoriteProfile $favoriteProfile): static
    {
        if (!$this->favoriteProfiles->contains($favoriteProfile)) {
            $this->favoriteProfiles->add($favoriteProfile);
            $favoriteProfile->setRecruiterProfile($this);
        }

        return $this;
    }

    public function removeFavoriteProfile(FavoriteProfile $favoriteProfile): static
    {
        if ($this->favoriteProfiles->removeElement($favoriteProfile)) {
            if ($favoriteProfile->getRecruiterProfile() === $this) {
                $favoriteProfile->setRecruiterProfile(null);
            }
        }

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }
}
