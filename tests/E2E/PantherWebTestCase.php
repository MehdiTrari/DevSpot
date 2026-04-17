<?php

namespace App\Tests\E2E;

use App\Entity\DeveloperProfile;
use App\Entity\Position;
use App\Entity\JobOffer;
use App\Entity\RecruiterProfile;
use App\Entity\Skill;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\OfferStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class PantherWebTestCase extends PantherTestCase
{
    protected function visit(Client $client, string $path): void
    {
        $baseUri = $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? sprintf('http://127.0.0.1:%s', $_SERVER['PANTHER_WEB_SERVER_PORT'] ?? '9080');
        $client->getWebDriver()->navigate()->to(rtrim($baseUri, '/').$path);
        $client->waitFor('body');
    }

    protected function resetDatabase(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ([] !== $metadata) {
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
        }

        $entityManager->clear();

        return $entityManager;
    }

    protected function disconnectKernel(EntityManagerInterface $entityManager): void
    {
        $entityManager->clear();
        $entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    protected function createApplicant(EntityManagerInterface $entityManager, string $email, string $password): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    protected function createRecruiter(EntityManagerInterface $entityManager, string $email, string $password): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_RECRUITER']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $recruiterProfile = new RecruiterProfile();
        $recruiterProfile->setFirstName('Mylène');
        $recruiterProfile->setLastName('Recruiter');
        $recruiterProfile->setJobTitle('Talent Partner');
        $recruiterProfile->setWorkEmail($email);
        $recruiterProfile->setUser($user);
        $user->setRecruiterProfile($recruiterProfile);

        $entityManager->persist($user);
        $entityManager->persist($recruiterProfile);
        $entityManager->flush();

        return $user;
    }

    protected function createJobOffer(EntityManagerInterface $entityManager, User $recruiter, array $overrides = []): JobOffer
    {
        $recruiterProfile = $recruiter->getRecruiterProfile();
        self::assertInstanceOf(RecruiterProfile::class, $recruiterProfile);

        $offer = new JobOffer();
        $offer->setRecruiterProfile($recruiterProfile);
        $offer->setTitle((string) ($overrides['title'] ?? 'Développeur Symfony'));
        $offer->setDescription((string) ($overrides['description'] ?? 'Nous cherchons un développeur Symfony full-stack pour renforcer l\'équipe produit.'));
        $offer->setLocation($overrides['location'] ?? 'Lyon');
        $offer->setExperienceLevel((int) ($overrides['experienceLevel'] ?? 2));
        $offer->setStatus($overrides['status'] ?? OfferStatus::PUBLISHED);

        $entityManager->persist($offer);
        $entityManager->flush();

        return $offer;
    }

    /**
     * @return array{skill: Skill, technology: Technology, position: Position}
     */
    protected function createReferenceCatalog(EntityManagerInterface $entityManager): array
    {
        $skill = (new Skill())
            ->setName('php')
            ->setCategory('backend');

        $technology = (new Technology())
            ->setName('symfony')
            ->setCategory('backend');

        $position = (new Position())
            ->setName('backend_developer');

        $entityManager->persist($skill);
        $entityManager->persist($technology);
        $entityManager->persist($position);
        $entityManager->flush();

        return [
            'skill' => $skill,
            'technology' => $technology,
            'position' => $position,
        ];
    }

    protected function createPublicProfile(EntityManagerInterface $entityManager, string $email = null, string $password = 'password123'): DeveloperProfile
    {
        $email ??= sprintf('public_%s@example.com', bin2hex(random_bytes(6)));
        $user = $this->createApplicant($entityManager, $email, $password);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Nadia');
        $profile->setLastName('Front');
        $profile->setHeadline('Développeuse full-stack');
        $profile->setBio('Portfolio de test pour Panther.');
        $profile->setCity('Lyon');
        $profile->setCountry('France');
        $profile->setSlug('panther-'.bin2hex(random_bytes(5)));
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
    }

    protected function createPrivateGeneratedProfile(EntityManagerInterface $entityManager, string $email = null, string $password = 'password123'): DeveloperProfile
    {
        $email ??= sprintf('private_%s@example.com', bin2hex(random_bytes(6)));
        $user = $this->createApplicant($entityManager, $email, $password);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Luc');
        $profile->setLastName('Privé');
        $profile->setHeadline('Développeur backend');
        $profile->setBio('Portfolio privé de test.');
        $profile->setCity('Paris');
        $profile->setCountry('France');
        $profile->setSlug('private-'.bin2hex(random_bytes(5)));
        $profile->setIsPublic(false);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
    }

    protected function login(Client $client, string $email, string $password): void
    {
        $this->visit($client, '/login');
        $client->waitFor('#inputEmail');
        $this->type($client, '#inputEmail', $email);
        $this->type($client, '#inputPassword', $password);
        $this->click($client, 'button[type="submit"]');

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $currentUrl = $client->getWebDriver()->getCurrentURL();

            if (!str_contains($currentUrl, '/login')) {
                $client->waitFor('body');

                return;
            }

            usleep(100000);
        }

        self::fail('La connexion n\'a pas redirigé hors de la page de login.');
    }

    protected function click(Client $client, string $cssSelector): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector($cssSelector))->click();
    }

    protected function clickByJs(Client $client, string $cssSelector): void
    {
        $client->executeScript(<<<'JS'
            const element = document.querySelector(arguments[0]);
            if (!element) {
                return false;
            }

            element.click();

            return true;
        JS, [$cssSelector]);
    }

    protected function type(Client $client, string $cssSelector, string $value): void
    {
        $element = $client->getWebDriver()->findElement(WebDriverBy::cssSelector($cssSelector));
        $element->clear();
        $element->sendKeys($value);
    }

    protected function setValue(Client $client, string $cssSelector, string $value): void
    {
        $client->executeScript(<<<'JS'
            const element = document.querySelector(arguments[0]);
            if (!element) {
                return;
            }

            element.value = arguments[1];
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$cssSelector, $value]);
    }

    protected function getTextContent(Client $client, string $cssSelector): string
    {
        $result = $client->executeScript(<<<'JS'
            const element = document.querySelector(arguments[0]);

            return element ? (element.textContent || '').trim() : null;
        JS, [$cssSelector]);

        return is_string($result) ? trim($result) : '';
    }

    protected function waitForTextContent(Client $client, string $cssSelector, string $expected, int $timeoutMs = 5000): string
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $lastText = '';

        while (microtime(true) < $deadline) {
            $lastText = $this->getTextContent($client, $cssSelector);

            if (str_contains($lastText, $expected)) {
                return $lastText;
            }

            usleep(100000);
        }

        return $lastText;
    }

    protected function selectOptionByText(Client $client, string $cssSelector, string $visibleText): void
    {
        $select = new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::cssSelector($cssSelector)));
        $select->selectByVisibleText($visibleText);
    }

    protected function selectOptionByValue(Client $client, string $cssSelector, string $value): void
    {
        $client->executeScript(<<<'JS'
            const select = document.querySelector(arguments[0]);
            if (!select) {
                return;
            }

            select.value = arguments[1];
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$cssSelector, $value]);
    }

    protected function selectMultipleOptionsByText(Client $client, string $cssSelector, array $visibleTexts): void
    {
        $select = new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::cssSelector($cssSelector)));

        foreach ($visibleTexts as $visibleText) {
            $select->selectByVisibleText($visibleText);
        }
    }

    protected function selectMultipleOptionsByValue(Client $client, string $cssSelector, array $values): void
    {
        $client->executeScript(<<<'JS'
            const select = document.querySelector(arguments[0]);
            if (!select) {
                return;
            }

            const values = arguments[1];
            Array.from(select.options).forEach((option) => {
                option.selected = values.includes(option.value);
            });

            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        JS, [$cssSelector, $values]);
    }

    protected function completeStep1(Client $client, array $overrides = []): void
    {
        $values = array_merge([
            'developer_profile[firstName]' => 'Mylène',
            'developer_profile[lastName]' => 'Martin',
            'developer_profile[headline]' => 'Développeuse Symfony',
            'developer_profile[city]' => 'Lyon',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'remote',
            'developer_profile[experienceLevel]' => 'junior',
            'developer_profile[yearsExperience]' => '3',
            'developer_profile[bio]' => 'Je construis des applications Symfony modernes.',
        ], $overrides);

        $client->waitFor('#developer_profile_firstName');

        $this->type($client, '#developer_profile_firstName', (string) $values['developer_profile[firstName]']);
        $this->type($client, '#developer_profile_lastName', (string) $values['developer_profile[lastName]']);
        $this->type($client, '#developer_profile_headline', (string) $values['developer_profile[headline]']);
        $this->type($client, '#developer_profile_city', (string) $values['developer_profile[city]']);
        $this->type($client, '#developer_profile_country', (string) $values['developer_profile[country]']);
        $this->selectOptionByValue($client, '#developer_profile_locationType', (string) $values['developer_profile[locationType]']);
        $this->selectOptionByValue($client, '#developer_profile_experienceLevel', (string) $values['developer_profile[experienceLevel]']);
        $this->type($client, '#developer_profile_yearsExperience', (string) $values['developer_profile[yearsExperience]']);
        $this->type($client, '#developer_profile_bio', (string) $values['developer_profile[bio]']);

        if (null !== ($values['developer_profile[avatarFile]'] ?? null)) {
            $this->setValue($client, '#developer_profile_avatarFile', (string) $values['developer_profile[avatarFile]']);
        }

        if (null !== $client->getCrawler()->filter('#profile-step1-create-form')->count()) {
            $this->click($client, '#profile-step1-create-form button[type="submit"]');

            return;
        }

        $this->click($client, '#profile-step1-form button[type="submit"]');
    }

    protected function completeStep2(Client $client, array $catalog): void
    {
        $this->click($client, '[data-collection-add="#profile-skills-list"]');
        $client->waitFor('#developer_profile_profileSkills_0_skill');
        $this->selectOptionByValue($client, '#developer_profile_profileSkills_0_skill', (string) $catalog['skill']->getId());
        $this->selectOptionByText($client, '#developer_profile_profileSkills_0_level', 'Avancé');
        $this->type($client, '#developer_profile_profileSkills_0_years', '5');

        $this->click($client, '[data-collection-add="#experiences-list"]');
        $client->waitFor('#developer_profile_experiences_0_companyName');
        $this->type($client, '#developer_profile_experiences_0_companyName', 'Acme');
        $this->type($client, '#developer_profile_experiences_0_title', 'Développeuse Symfony');
        $this->setValue($client, '#developer_profile_experiences_0_startDate', '2022-01-01');
        $this->setValue($client, '#developer_profile_experiences_0_endDate', '2024-01-01');
        $this->type($client, '#developer_profile_experiences_0_description', 'Développement de fonctionnalités Symfony et API.');
        $this->selectMultipleOptionsByValue($client, '#developer_profile_experiences_0_technologies', [(string) $catalog['technology']->getId()]);

        $this->click($client, '[data-collection-add="#education-list"]');
        $client->waitFor('#developer_profile_education_0_schoolName');
        $this->type($client, '#developer_profile_education_0_schoolName', 'EPSI');
        $this->type($client, '#developer_profile_education_0_degree', 'Master');
        $this->type($client, '#developer_profile_education_0_field', 'Informatique');
        $this->setValue($client, '#developer_profile_education_0_startDate', '2020-09-01');
        $this->setValue($client, '#developer_profile_education_0_endDate', '2022-06-30');
        $this->type($client, '#developer_profile_education_0_description', 'Formation en développement web avancé.');

        $this->click($client, '#profile-step2-form button[type="submit"]');
    }

    protected function completeStep3(Client $client, array $overrides = []): void
    {
        $values = array_merge([
            'developer_profile[githubUrl]' => 'https://github.com/mylene-martin',
            'developer_profile[linkedinUrl]' => 'https://www.linkedin.com/in/mylene-martin',
            'developer_profile[portfolioUrl]' => 'https://mylene.dev',
        ], $overrides);

        $client->waitFor('#profile-step3-form');
        $this->setValue($client, '#developer_profile_githubUrl', (string) $values['developer_profile[githubUrl]']);
        $this->setValue($client, '#developer_profile_linkedinUrl', (string) $values['developer_profile[linkedinUrl]']);
        $this->setValue($client, '#developer_profile_portfolioUrl', (string) $values['developer_profile[portfolioUrl]']);
        $this->click($client, '#profile-step3-form button[type="submit"]');
    }

    protected function completeStep4(Client $client, array $catalog): void
    {
        $client->waitFor('#profile-step4-form');
        $this->selectMultipleOptionsByValue($client, '#developer_profile_desiredPositions', [(string) $catalog['position']->getId()]);
        $this->click($client, '#profile-step4-form button[type="submit"]');
    }
}