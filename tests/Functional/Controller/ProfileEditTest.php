<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use App\Enum\UserStatus;
use App\Repository\DeveloperProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileEditTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testEditStep1UpdatesProfileAndUpdatedAt(): void
    {
        $client = static::createClient();
        $email = sprintf('edit_step1_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $user = $this->createUserWithProfile($email, $password);

        $before = new \DateTimeImmutable('now');

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Jean',
            'developer_profile[lastName]' => 'Dupont',
            'developer_profile[headline]' => 'Développeur PHP Senior',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'hybrid',
            'developer_profile[experienceLevel]' => 'senior',
            'developer_profile[yearsExperience]' => '8',
            'developer_profile[bio]' => 'Passionné de Symfony et PHP.',
        ]));

        self::assertResponseRedirects();
        $client->followRedirect();

        /** @var DeveloperProfileRepository $profileRepo */
        $profileRepo = static::getContainer()->get(DeveloperProfileRepository::class);
        /** @var UserRepository $userRepo */
        $userRepo = static::getContainer()->get(UserRepository::class);
        $updatedUser = $userRepo->findOneBy(['email' => $email]);
        $profile = $profileRepo->findOneBy(['user' => $updatedUser]);

        self::assertNotNull($profile);
        self::assertSame('Jean', $profile->getFirstName());
        self::assertSame('Dupont', $profile->getLastName());
        self::assertSame('Développeur PHP Senior', $profile->getHeadline());
        self::assertSame(LocationType::HYBRID, $profile->getLocationType());
        self::assertSame(ExperienceLevel::SENIOR, $profile->getExperienceLevel());
        self::assertSame(8, $profile->getYearsExperience());
        self::assertNotNull($profile->getUpdatedAt());
        // La DB peut tronquer les microsecondes, on vérifie que updatedAt est récent (< 30s)
        $diffSeconds = abs($profile->getUpdatedAt()->getTimestamp() - $before->getTimestamp());
        self::assertLessThan(30, $diffSeconds, 'updatedAt devrait être récent après mise à jour');
    }

    public function testStep1FormIsPrefilledWithExistingData(): void
    {
        $client = static::createClient();
        $email = sprintf('prefill_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithProfile($email, $password, 'Alice', 'Martin', 'Lead Développeuse');

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseIsSuccessful();

        self::assertSame('Alice', $crawler->filter('#developer_profile_firstName')->attr('value'));
        self::assertSame('Martin', $crawler->filter('#developer_profile_lastName')->attr('value'));
        self::assertSame('Lead Développeuse', $crawler->filter('#developer_profile_headline')->attr('value'));
    }

    public function testUnauthenticatedUserCannotAccessEditPages(): void
    {
        $client = static::createClient();

        $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseRedirects('/403');

        $client->request('GET', '/applicant/profile/step-2');
        self::assertResponseRedirects('/403');

        $client->request('GET', '/applicant/profile/step-3');
        self::assertResponseRedirects('/403');

        $client->request('GET', '/applicant/profile/step-4');
        self::assertResponseRedirects('/403');
    }

    public function testStep1ValidationRejectsTooLongFields(): void
    {
        $client = static::createClient();
        $email = sprintf('valid_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithProfile($email, $password);

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseIsSuccessful();

        $tooLong = str_repeat('a', 256);

        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => $tooLong,
            'developer_profile[lastName]' => 'Dupont',
            'developer_profile[headline]' => 'Dev',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'remote',
            'developer_profile[experienceLevel]' => 'junior',
            'developer_profile[yearsExperience]' => '1',
            'developer_profile[bio]' => 'Test.',
        ]));

        self::assertResponseIsUnprocessable();
    }

    public function testPublicProfilePageIsAccessible(): void
    {
        $client = static::createClient();
        $email = sprintf('public_%s@example.com', bin2hex(random_bytes(8)));
        $user = $this->createUserWithProfile($email, 'password123', 'Clara', 'Lebrun', 'Développeuse React');

        $profile = $user->getDeveloperProfile();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $em->flush();

        $client->request('GET', '/profil/' . $profile->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Clara');
        self::assertSelectorTextContains('body', 'Développeuse React');
    }

    public function testPrivateProfileReturnsForbiddenForNonOwner(): void
    {
        $client = static::createClient();
        $email = sprintf('private_%s@example.com', bin2hex(random_bytes(8)));
        $user = $this->createUserWithProfile($email, 'password123', 'Bob', 'Secret', 'Développeur invisible');

        $profile = $user->getDeveloperProfile();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $em->flush();

        $slug = $profile->getSlug();

        $client->request('GET', '/profil/' . $slug);
        self::assertResponseRedirects('/403');
        $client->followRedirect();
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanViewOwnPrivateProfile(): void
    {
        $client = static::createClient();
        $email = sprintf('owner_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $user = $this->createUserWithProfile($email, $password, 'Luc', 'Privé', 'Dev privé');

        $profile = $user->getDeveloperProfile();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $em->flush();

        $this->login($client, $email, $password);

        $client->request('GET', '/profil/' . $profile->getSlug());
        self::assertResponseIsSuccessful();
    }

    public function testPublicProfileReflectsProfileChanges(): void
    {
        $client = static::createClient();
        $email = sprintf('reflect_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $user = $this->createUserWithProfile($email, $password, 'Marc', 'Leblanc', 'Dev Backend');

        $profile = $user->getDeveloperProfile();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $em->flush();

        $slug = $profile->getSlug();

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Marc',
            'developer_profile[lastName]' => 'Leblanc',
            'developer_profile[headline]' => 'Lead Architect Cloud',
            'developer_profile[city]' => 'Bordeaux',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'remote',
            'developer_profile[experienceLevel]' => 'lead',
            'developer_profile[yearsExperience]' => '10',
            'developer_profile[bio]' => 'Expert cloud.',
        ]));
        self::assertResponseRedirects();

        $client->request('GET', '/profil/' . $slug);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Lead Architect Cloud');
    }

    public function testConfirmationMessageIsDisplayedAfterUpdate(): void
    {
        $client = static::createClient();
        $email = sprintf('flash_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithProfile($email, $password);

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Test',
            'developer_profile[lastName]' => 'Flash',
            'developer_profile[headline]' => 'Développeur',
            'developer_profile[city]' => 'Lyon',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'onsite',
            'developer_profile[experienceLevel]' => 'junior',
            'developer_profile[yearsExperience]' => '1',
            'developer_profile[bio]' => 'Test.',
        ]));

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'mise à jour');
    }

    public function testStep1RejectsNegativeYearsExperience(): void
    {
        $client = static::createClient();
        $email = sprintf('negative_years_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithProfile($email, $password);

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Jean',
            'developer_profile[lastName]' => 'Dupont',
            'developer_profile[headline]' => 'Développeur PHP Senior',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'hybrid',
            'developer_profile[experienceLevel]' => 'senior',
            'developer_profile[yearsExperience]' => '-1',
            'developer_profile[bio]' => 'Passionné de Symfony et PHP.',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'positif ou nul');
    }

    public function testStep1CanSaveAndExitToDashboard(): void
    {
        $client = static::createClient();
        $email = sprintf('save_exit_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithProfile($email, $password);

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/profile/step-1');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Jean',
            'developer_profile[lastName]' => 'Dupont',
            'developer_profile[headline]' => 'Développeur PHP Senior',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'hybrid',
            'developer_profile[experienceLevel]' => 'senior',
            'developer_profile[yearsExperience]' => '8',
            'developer_profile[bio]' => 'Passionné de Symfony et PHP.',
        ]);
        $form['form_action'] = 'save_and_exit';
        $client->submit($form);

        self::assertResponseRedirects('/applicant');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'revenu au dashboard');
    }

    private function login($client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $client->followRedirect();
    }

    private function createUserWithProfile(
        string $email,
        string $password,
        string $firstName = 'Test',
        string $lastName = 'Profil',
        string $headline = 'Développeur',
    ): User {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($em);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $profile = new DeveloperProfile();
        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setHeadline($headline);
        $profile->setSlug(strtolower($firstName . $lastName) . bin2hex(random_bytes(3)));
        $profile->setIsPublic(false);
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $em->persist($user);
        $em->persist($profile);
        $em->flush();

        return $user;
    }

    private function ensureSchemaExists(EntityManagerInterface $entityManager): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune métadonnée Doctrine disponible pour créer le schéma de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::$schemaInitialized = true;
    }
}
