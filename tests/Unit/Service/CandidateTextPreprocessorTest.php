<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeveloperProfile;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\Position;
use App\Entity\ProfileSkill;
use App\Entity\Skill;
use App\Entity\Technology;
use App\Enum\ExperienceLevel;
use App\Enum\SkillLevel;
use App\Service\CandidateTextPreprocessor;
use PHPUnit\Framework\TestCase;

final class CandidateTextPreprocessorTest extends TestCase
{
    public function testBuildCandidateTextMatchesSharedFixtureContract(): void
    {
        $fixture = $this->loadFixture();
        $profile = $this->buildDeveloperProfileFromFixture($fixture['profile']);

        $text = (new CandidateTextPreprocessor())->buildCandidateText($profile);

        self::assertSame($fixture['expectedText'], $text);
    }

    public function testBuildCandidateTextNormalizesCanonicalizesAndOmitsIdentityHeavyFields(): void
    {
        $profile = (new DeveloperProfile())
            ->setFirstName('Alice')
            ->setLastName('Dupont')
            ->setHeadline('Developpeuse reactjs / symfony')
            ->setBio('Contact: alice@example.com - Portfolio https://portfolio.example.com - stack react.js, postgrela base de donnees, api platform')
            ->setExperienceLevel(ExperienceLevel::JUNIOR)
            ->setYearsExperience(2);

        $profile->addDesiredPosition((new Position())->setName('Developpeur React'));
        $profile->addDesiredPosition((new Position())->setName('Developpeur React'));

        $profile->addProfileSkill(
            (new ProfileSkill())
                ->setSkill((new Skill())->setName('reactjs')->setCategory('Frontend'))
                ->setLevel(SkillLevel::ADVANCED)
                ->setYears(2)
        );
        $profile->addProfileSkill(
            (new ProfileSkill())
                ->setSkill((new Skill())->setName('Communication')->setCategory('Soft Skills'))
                ->setLevel(SkillLevel::INTERMEDIATE)
        );

        $profile->addExperience(
            (new Experience())
                ->setCompanyName('Secret Company')
                ->setTitle('Frontend Engineer')
                ->setDescription('Build reactjs dashboards with postgrela base de donnees and api platform.')
                ->setStartDate(new \DateTime('2024-01-01'))
                ->setEndDate(new \DateTime('2025-01-01'))
                ->setIsCurrent(false)
                ->addTechnology((new Technology())->setName('react.js')->setCategory('Frontend'))
                ->addTechnology((new Technology())->setName('Postgres')->setCategory('Backend'))
        );

        $profile->addEducation(
            (new Education())
                ->setSchoolName('Very Famous School')
                ->setDegree('Master')
                ->setField('Web engineering')
                ->setDescription('API Platform and Symfony projects.')
        );

        $preprocessor = new CandidateTextPreprocessor();

        $text = $preprocessor->buildCandidateText($profile);

        self::assertStringContainsString('Headline: Developpeuse React / Symfony', $text);
        self::assertStringContainsString('Summary: stack React, PostgreSQL, API Platform. Experience level: junior. Years of experience: 2', $text);
        self::assertStringContainsString('Target roles: Developpeur React', $text);
        self::assertStringContainsString('Core skills: React (advanced, 2 years)', $text);
        self::assertStringContainsString('Soft skills: Communication (intermediate)', $text);
        self::assertStringContainsString('Technologies: React, PostgreSQL', $text);
        self::assertStringContainsString('Education:', $text);
        self::assertStringContainsString('Degree: Master. Field: Web engineering. Summary: API Platform and Symfony projects', $text);
        self::assertStringNotContainsString('alice@example.com', $text);
        self::assertStringNotContainsString('portfolio.example.com', $text);
        self::assertStringNotContainsString('Secret Company', $text);
        self::assertStringNotContainsString('Very Famous School', $text);
    }

    /**
     * @return array{profile: array<string, mixed>, expectedText: string}
     */
    private function loadFixture(): array
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/noisy_candidate_matching_profile.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($fixture);
        self::assertArrayHasKey('profile', $fixture);
        self::assertArrayHasKey('expectedText', $fixture);

        return $fixture;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildDeveloperProfileFromFixture(array $data): DeveloperProfile
    {
        $profile = (new DeveloperProfile())
            ->setFirstName((string) ($data['firstName'] ?? ''))
            ->setLastName((string) ($data['lastName'] ?? ''))
            ->setHeadline((string) ($data['headline'] ?? ''))
            ->setBio((string) ($data['bio'] ?? ''))
            ->setYearsExperience(isset($data['yearsExperience']) ? (int) $data['yearsExperience'] : null);

        if (isset($data['experienceLevel']) && is_string($data['experienceLevel']) && '' !== trim($data['experienceLevel'])) {
            $profile->setExperienceLevel(ExperienceLevel::from($data['experienceLevel']));
        }

        foreach ($data['desiredPositions'] ?? [] as $desiredPosition) {
            $profile->addDesiredPosition((new Position())->setName((string) $desiredPosition));
        }

        foreach ($data['profileSkills'] ?? [] as $profileSkillData) {
            if (!is_array($profileSkillData)) {
                continue;
            }

            $skill = (new Skill())
                ->setName((string) ($profileSkillData['skill'] ?? ''))
                ->setCategory((string) ($profileSkillData['category'] ?? 'Backend'));

            $profileSkill = (new ProfileSkill())->setSkill($skill);

            if (isset($profileSkillData['level']) && is_string($profileSkillData['level']) && '' !== trim($profileSkillData['level'])) {
                $profileSkill->setLevel(SkillLevel::from($profileSkillData['level']));
            }

            if (array_key_exists('years', $profileSkillData)) {
                $profileSkill->setYears((int) $profileSkillData['years']);
            }

            $profile->addProfileSkill($profileSkill);
        }

        foreach ($data['experiences'] ?? [] as $experienceData) {
            if (!is_array($experienceData)) {
                continue;
            }

            $experience = (new Experience())
                ->setCompanyName((string) ($experienceData['companyName'] ?? ''))
                ->setTitle((string) ($experienceData['title'] ?? ''))
                ->setDescription((string) ($experienceData['description'] ?? ''))
                ->setIsCurrent((bool) ($experienceData['isCurrent'] ?? false));

            if (isset($experienceData['startDate']) && is_string($experienceData['startDate']) && '' !== trim($experienceData['startDate'])) {
                $experience->setStartDate(new \DateTime($experienceData['startDate']));
            }

            if (isset($experienceData['endDate']) && is_string($experienceData['endDate']) && '' !== trim($experienceData['endDate'])) {
                $experience->setEndDate(new \DateTime($experienceData['endDate']));
            }

            foreach ($experienceData['technologies'] ?? [] as $technologyName) {
                $experience->addTechnology((new Technology())->setName((string) $technologyName)->setCategory('Generic'));
            }

            $profile->addExperience($experience);
        }

        foreach ($data['education'] ?? [] as $educationData) {
            if (!is_array($educationData)) {
                continue;
            }

            $education = (new Education())
                ->setSchoolName((string) ($educationData['schoolName'] ?? ''))
                ->setDegree((string) ($educationData['degree'] ?? ''))
                ->setField((string) ($educationData['field'] ?? ''))
                ->setDescription((string) ($educationData['description'] ?? ''));

            if (isset($educationData['startDate']) && is_string($educationData['startDate']) && '' !== trim($educationData['startDate'])) {
                $education->setStartDate(new \DateTime($educationData['startDate']));
            }

            if (isset($educationData['endDate']) && is_string($educationData['endDate']) && '' !== trim($educationData['endDate'])) {
                $education->setEndDate(new \DateTime($educationData['endDate']));
            }

            $profile->addEducation($education);
        }

        return $profile;
    }
}
