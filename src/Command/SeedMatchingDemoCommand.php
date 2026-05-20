<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DeveloperProfile;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\JobOffer;
use App\Entity\Position;
use App\Entity\ProfileSkill;
use App\Entity\RecruiterProfile;
use App\Entity\Skill;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\ContractType;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use App\Enum\SkillLevel;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed:matching-demo',
    description: 'Injecte un dataset de démonstration pour tester le matching offre ↔ développeur.',
)]
final class SeedMatchingDemoCommand extends Command
{
    private const DEMO_EMAIL_PATTERN = '%@demo.devspot.local';

    /** @var array<string, Skill> */
    private array $skillCache = [];

    /** @var array<string, Technology> */
    private array $technologyCache = [];

    /** @var array<string, Position> */
    private array $positionCache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Chemin vers un dataset JSON personnalisé.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $datasetArgument = $input->getArgument('dataset');
        $datasetPath = is_string($datasetArgument) && '' !== trim($datasetArgument)
            ? trim($datasetArgument)
            : $this->projectDir . '/docs/matching-demo-dataset.json';

        if (!str_starts_with($datasetPath, '/') && !preg_match('/^[A-Za-z]:\\\\/', $datasetPath)) {
            $datasetPath = $this->projectDir . '/' . $datasetPath;
        }

        if (!is_file($datasetPath)) {
            $io->error(sprintf('Dataset introuvable: %s', $datasetPath));

            return Command::FAILURE;
        }

        /** @var array{
         *     recruiter: array{user: array<string, mixed>, profile: array<string, mixed>},
         *     developers: list<array{user: array<string, mixed>, profile: array<string, mixed>}>,
         *     offers: list<array<string, mixed>>
         * } $dataset
         */
        $dataset = json_decode((string) file_get_contents($datasetPath), true, 512, JSON_THROW_ON_ERROR);

        $this->removeExistingDemoUsers();

        $recruiterProfile = $this->createRecruiterProfile($dataset['recruiter']);
        foreach ($dataset['developers'] as $developerData) {
            $this->createDeveloperProfile($developerData);
        }

        foreach ($dataset['offers'] as $offerData) {
            $this->createOffer($recruiterProfile, $offerData);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Dataset de matching importé: %d développeurs, %d offres, recruteur %s.',
            count($dataset['developers']),
            count($dataset['offers']),
            (string) $dataset['recruiter']['user']['email'],
        ));
        $io->note('Compte recruteur démo: recruiter.demo@demo.devspot.local / Demo1234!');

        return Command::SUCCESS;
    }

    private function removeExistingDemoUsers(): void
    {
        $connection = $this->entityManager->getConnection();
        $pattern = self::DEMO_EMAIL_PATTERN;

        $connection->executeStatement(
            'DELETE FROM favorite_profile
             WHERE developer_profile_id IN (
                SELECT d.id
                FROM developer_profile d
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )
             OR recruiter_profile_id IN (
                SELECT r.id
                FROM recruiter_profile r
                JOIN "user" u ON u.id = r.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM job_offer
             WHERE recruiter_profile_id IN (
                SELECT r.id
                FROM recruiter_profile r
                JOIN "user" u ON u.id = r.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM experience_technology
             WHERE experience_id IN (
                SELECT e.id
                FROM experience e
                JOIN developer_profile d ON d.id = e.developer_profile_id
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM profile_skill
             WHERE developer_profile_id IN (
                SELECT d.id
                FROM developer_profile d
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM developer_profile_position
             WHERE developer_profile_id IN (
                SELECT d.id
                FROM developer_profile d
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM education
             WHERE developer_profile_id IN (
                SELECT d.id
                FROM developer_profile d
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM experience
             WHERE developer_profile_id IN (
                SELECT d.id
                FROM developer_profile d
                JOIN "user" u ON u.id = d.user_id
                WHERE u.email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM developer_profile
             WHERE user_id IN (
                SELECT id FROM "user" WHERE email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM recruiter_profile
             WHERE user_id IN (
                SELECT id FROM "user" WHERE email LIKE :pattern
             )',
            ['pattern' => $pattern],
        );

        $connection->executeStatement(
            'DELETE FROM "user" WHERE email LIKE :pattern',
            ['pattern' => $pattern],
        );

        $this->entityManager->clear();
    }

    /**
     * @param array{user: array<string, mixed>, profile: array<string, mixed>} $recruiterData
     */
    private function createRecruiterProfile(array $recruiterData): RecruiterProfile
    {
        $userData = $recruiterData['user'];
        $profileData = $recruiterData['profile'];

        $user = (new User())
            ->setEmail((string) $userData['email'])
            ->setRoles(array_values(array_map('strval', $userData['roles'] ?? ['ROLE_RECRUITER'])))
            ->setIsVerified((bool) ($userData['isVerified'] ?? true))
            ->setStatus($this->userStatus((string) ($userData['status'] ?? 'active')))
            ->setPassword($this->passwordHasher->hashPassword(new User(), (string) $userData['password']));

        $profile = (new RecruiterProfile())
            ->setFirstName((string) $profileData['firstName'])
            ->setLastName((string) $profileData['lastName'])
            ->setJobTitle((string) $profileData['jobTitle'])
            ->setWorkEmail($this->nullableString($profileData['workEmail'] ?? null))
            ->setPhone($this->nullableString($profileData['phone'] ?? null))
            ->setUser($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);

        return $profile;
    }

    /**
     * @param array{user: array<string, mixed>, profile: array<string, mixed>} $developerData
     */
    private function createDeveloperProfile(array $developerData): void
    {
        $userData = $developerData['user'];
        $profileData = $developerData['profile'];

        $user = (new User())
            ->setEmail((string) $userData['email'])
            ->setRoles(array_values(array_map('strval', $userData['roles'] ?? ['ROLE_APPLICANT'])))
            ->setIsVerified((bool) ($userData['isVerified'] ?? true))
            ->setStatus($this->userStatus((string) ($userData['status'] ?? 'active')))
            ->setPassword($this->passwordHasher->hashPassword(new User(), (string) $userData['password']));

        $profile = (new DeveloperProfile())
            ->setFirstName((string) $profileData['firstName'])
            ->setLastName((string) $profileData['lastName'])
            ->setHeadline((string) $profileData['headline'])
            ->setBio($this->nullableString($profileData['bio'] ?? null))
            ->setCity($this->nullableString($profileData['city'] ?? null))
            ->setCountry($this->nullableString($profileData['country'] ?? null))
            ->setLocationType($this->locationType($profileData['locationType'] ?? null))
            ->setExperienceLevel($this->experienceLevel($profileData['experienceLevel'] ?? null))
            ->setYearsExperience(isset($profileData['yearsExperience']) ? (int) $profileData['yearsExperience'] : null)
            ->setIsPublic((bool) ($profileData['isPublic'] ?? true))
            ->setSlug((string) $profileData['slug'])
            ->setPortfolioUrl($this->nullableString($profileData['portfolioUrl'] ?? null))
            ->setGithubUrl($this->nullableString($profileData['githubUrl'] ?? null))
            ->setLinkedinUrl($this->nullableString($profileData['linkedinUrl'] ?? null))
            ->setPortfolioGeneratedAt(isset($profileData['portfolioGeneratedAt']) ? new \DateTimeImmutable((string) $profileData['portfolioGeneratedAt']) : new \DateTimeImmutable())
            ->setUser($user);

        foreach ($profileData['desiredPositions'] ?? [] as $positionName) {
            $profile->addDesiredPosition($this->findOrCreatePosition((string) $positionName));
        }

        foreach ($profileData['profileSkills'] ?? [] as $profileSkillData) {
            $profileSkill = (new ProfileSkill())
                ->setSkill($this->findOrCreateSkill((string) $profileSkillData['skill']))
                ->setLevel($this->skillLevel($profileSkillData['level'] ?? null))
                ->setYears(isset($profileSkillData['years']) ? (int) $profileSkillData['years'] : null);

            $profile->addProfileSkill($profileSkill);
            $this->entityManager->persist($profileSkill);
        }

        foreach ($profileData['experiences'] ?? [] as $experienceData) {
            $experience = (new Experience())
                ->setCompanyName((string) $experienceData['companyName'])
                ->setTitle((string) $experienceData['title'])
                ->setStartDate(new \DateTime((string) $experienceData['startDate']))
                ->setIsCurrent((bool) ($experienceData['isCurrent'] ?? false))
                ->setDescription((string) $experienceData['description']);

            if (isset($experienceData['endDate']) && null !== $experienceData['endDate']) {
                $experience->setEndDate(new \DateTime((string) $experienceData['endDate']));
            } else {
                $experience->setEndDate(new \DateTime('today'));
            }

            foreach ($experienceData['technologies'] ?? [] as $technologyName) {
                $experience->addTechnology($this->findOrCreateTechnology((string) $technologyName));
            }

            $profile->addExperience($experience);
            $this->entityManager->persist($experience);
        }

        foreach ($profileData['education'] ?? [] as $educationData) {
            $education = (new Education())
                ->setSchoolName((string) $educationData['schoolName'])
                ->setDegree((string) ($educationData['degree'] ?? ''))
                ->setField((string) ($educationData['field'] ?? ''))
                ->setStartDate(isset($educationData['startDate']) && null !== $educationData['startDate'] ? new \DateTime((string) $educationData['startDate']) : null)
                ->setEndDate(isset($educationData['endDate']) && null !== $educationData['endDate'] ? new \DateTime((string) $educationData['endDate']) : null)
                ->setDescription($this->nullableString($educationData['description'] ?? null));

            $profile->addEducation($education);
            $this->entityManager->persist($education);
        }

        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
    }

    /**
     * @param array<string, mixed> $offerData
     */
    private function createOffer(RecruiterProfile $recruiterProfile, array $offerData): void
    {
        $offer = (new JobOffer())
            ->setTitle((string) $offerData['title'])
            ->setDescription((string) $offerData['description'])
            ->setLocation($this->nullableString($offerData['location'] ?? null))
            ->setLocationType($this->locationType($offerData['locationType'] ?? null))
            ->setContractType($this->contractType($offerData['contractType'] ?? null))
            ->setExperienceLevel(isset($offerData['experienceLevel']) ? (int) $offerData['experienceLevel'] : null)
            ->setSalaryMin(isset($offerData['salaryMin']) ? (int) $offerData['salaryMin'] : null)
            ->setSalaryMax(isset($offerData['salaryMax']) ? (int) $offerData['salaryMax'] : null)
            ->setIsActive((bool) ($offerData['isActive'] ?? true))
            ->setRecruiterProfile($recruiterProfile);

        $this->entityManager->persist($offer);
    }

    private function findOrCreateSkill(string $name): Skill
    {
        $cacheKey = mb_strtolower($name);
        if (isset($this->skillCache[$cacheKey])) {
            return $this->skillCache[$cacheKey];
        }

        $skill = $this->entityManager->getRepository(Skill::class)->findOneBy(['name' => $name]);
        if ($skill instanceof Skill) {
            $this->skillCache[$cacheKey] = $skill;

            return $skill;
        }

        $skill = (new Skill())
            ->setName($name)
            ->setCategory('Imported Demo');

        $this->entityManager->persist($skill);
        $this->skillCache[$cacheKey] = $skill;

        return $skill;
    }

    private function findOrCreateTechnology(string $name): Technology
    {
        $cacheKey = mb_strtolower($name);
        if (isset($this->technologyCache[$cacheKey])) {
            return $this->technologyCache[$cacheKey];
        }

        $technology = $this->entityManager->getRepository(Technology::class)->findOneBy(['name' => $name]);
        if ($technology instanceof Technology) {
            $this->technologyCache[$cacheKey] = $technology;

            return $technology;
        }

        $technology = (new Technology())
            ->setName($name)
            ->setCategory('Imported Demo');

        $this->entityManager->persist($technology);
        $this->technologyCache[$cacheKey] = $technology;

        return $technology;
    }

    private function findOrCreatePosition(string $name): Position
    {
        $cacheKey = mb_strtolower($name);
        if (isset($this->positionCache[$cacheKey])) {
            return $this->positionCache[$cacheKey];
        }

        $position = $this->entityManager->getRepository(Position::class)->findOneBy(['name' => $name]);
        if ($position instanceof Position) {
            $this->positionCache[$cacheKey] = $position;

            return $position;
        }

        $position = (new Position())
            ->setName($name);

        $this->entityManager->persist($position);
        $this->positionCache[$cacheKey] = $position;

        return $position;
    }

    private function userStatus(string $value): UserStatus
    {
        return match ($value) {
            'inactive' => UserStatus::SUSPENDED,
            default => UserStatus::tryFrom($value) ?? UserStatus::ACTIVE,
        };
    }

    private function locationType(mixed $value): ?LocationType
    {
        return is_string($value) && '' !== $value ? LocationType::from($value) : null;
    }

    private function experienceLevel(mixed $value): ?ExperienceLevel
    {
        return is_string($value) && '' !== $value ? ExperienceLevel::from($value) : null;
    }

    private function skillLevel(mixed $value): ?SkillLevel
    {
        return is_string($value) && '' !== $value ? SkillLevel::from($value) : null;
    }

    private function contractType(mixed $value): ?ContractType
    {
        return is_string($value) && '' !== $value ? ContractType::from($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
