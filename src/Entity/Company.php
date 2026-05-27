<?php

namespace App\Entity;

use App\Enum\CompanySize;
use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, RecruiterProfile>
     */
    #[ORM\OneToMany(targetEntity: RecruiterProfile::class, mappedBy: 'company')]
    private Collection $recruiterProfiles;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(nullable: true, enumType: CompanySize::class)]
    private ?CompanySize $size = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    public function __construct()
    {
        $this->recruiterProfiles = new ArrayCollection();
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

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, RecruiterProfile>
     */
    public function getRecruiterProfiles(): Collection
    {
        return $this->recruiterProfiles;
    }

    public function addRecruiterProfile(RecruiterProfile $recruiterProfile): static
    {
        if (!$this->recruiterProfiles->contains($recruiterProfile)) {
            $this->recruiterProfiles->add($recruiterProfile);
            $recruiterProfile->setCompany($this);
        }

        return $this;
    }

    public function removeRecruiterProfile(RecruiterProfile $recruiterProfile): static
    {
        if ($this->recruiterProfiles->removeElement($recruiterProfile)) {
            // set the owning side to null (unless already changed)
            if ($recruiterProfile->getCompany() === $this) {
                $recruiterProfile->setCompany(null);
            }
        }

        return $this;
    }

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): static
    {
        $this->industry = $industry;

        return $this;
    }

    public function getSize(): ?CompanySize
    {
        return $this->size;
    }

    public function setSize(?CompanySize $size): static
    {
        $this->size = $size;

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

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }
}
