<?php

namespace App\Entity;

use App\Enum\ContractType;
use App\Enum\LocationType;
use App\Enum\OfferStatus;
use App\Repository\JobOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobOfferRepository::class)]
class JobOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(nullable: true, enumType: LocationType::class)]
    private ?LocationType $locationType = null;

    #[ORM\Column(nullable: true, enumType: ContractType::class)]
    private ?ContractType $contractType = null;

    #[ORM\Column(nullable: true)]
    private ?int $experienceLevel = null;

    #[ORM\Column(nullable: true)]
    private ?int $salaryMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $salaryMax = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(length: 20, enumType: OfferStatus::class, options: ['default' => 'published'])]
    private OfferStatus $status = OfferStatus::PUBLISHED;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $applicationDeadline = null;

    #[ORM\ManyToOne(inversedBy: 'jobOffers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?RecruiterProfile $recruiterProfile = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->isActive = true;
        $this->status = OfferStatus::PUBLISHED;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getLocationType(): ?LocationType
    {
        return $this->locationType;
    }

    public function setLocationType(?LocationType $locationType): static
    {
        $this->locationType = $locationType;

        return $this;
    }

    public function getContractType(): ?ContractType
    {
        return $this->contractType;
    }

    public function setContractType(?ContractType $contractType): static
    {
        $this->contractType = $contractType;

        return $this;
    }

    public function getExperienceLevel(): ?int
    {
        return $this->experienceLevel;
    }

    public function setExperienceLevel(?int $experienceLevel): static
    {
        $this->experienceLevel = $experienceLevel;

        return $this;
    }

    public function getSalaryMin(): ?int
    {
        return $this->salaryMin;
    }

    public function setSalaryMin(?int $salaryMin): static
    {
        $this->salaryMin = $salaryMin;

        return $this;
    }

    public function getSalaryMax(): ?int
    {
        return $this->salaryMax;
    }

    public function setSalaryMax(?int $salaryMax): static
    {
        $this->salaryMax = $salaryMax;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getStatus(): OfferStatus
    {
        return $this->status;
    }

    public function setStatus(OfferStatus $status): static
    {
        $this->status = $status;
        $this->isActive = (OfferStatus::PUBLISHED === $status);
        $this->updatedAt = new \DateTimeImmutable();

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

    public function getRecruiterProfile(): ?RecruiterProfile
    {
        return $this->recruiterProfile;
    }

    public function setRecruiterProfile(?RecruiterProfile $recruiterProfile): static
    {
        $this->recruiterProfile = $recruiterProfile;

        return $this;
    }

    public function getApplicationDeadline(): ?\DateTimeImmutable
    {
        return $this->applicationDeadline;
    }

    public function setApplicationDeadline(?\DateTimeImmutable $applicationDeadline): static
    {
        $this->applicationDeadline = $applicationDeadline;

        return $this;
    }
}
