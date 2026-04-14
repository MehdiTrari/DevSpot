<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\DeveloperProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApplicantProfileFlowTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testApplicantCanCreateDeveloperProfile(): void
    {
        $client = static::createClient();
        $email = sprintf('profile_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');

        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Mylene',
            'developer_profile[lastName]' => 'Martin',
            'developer_profile[headline]' => 'Developpeuse Symfony',
            'developer_profile[bio]' => 'Profil cree depuis un test fonctionnel.',
            'developer_profile[city]' => 'Lyon',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'remote',
            'developer_profile[experienceLevel]' => 'junior',
            'developer_profile[yearsExperience]' => '2',
        ]));

        self::assertResponseRedirects('/applicant/profile/step-2');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Étape 2');
        self::assertSelectorExists('button[type="submit"]');

        /** @var DeveloperProfileRepository $profiles */
        $profiles = static::getContainer()->get(DeveloperProfileRepository::class);
        $profile = $profiles->findOneBy(['user' => static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email])]);

        self::assertNotNull($profile);
        self::assertSame('Mylene', $profile->getFirstName());
        self::assertSame('Martin', $profile->getLastName());
        self::assertSame('Developpeuse Symfony', $profile->getHeadline());
        self::assertNotEmpty($profile->getSlug());
    }

    public function testApplicantCanCreateDeveloperProfileWithAvatarUpload(): void
    {
        $client = static::createClient();
        $email = sprintf('avatar_ok_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();

        $imagePath = $this->createTemporaryPngFile();
        $storedAvatarPath = null;

        try {
            $form = $crawler->selectButton('Étape suivante')->form([
                'developer_profile[firstName]' => 'Mylene',
                'developer_profile[lastName]' => 'Martin',
                'developer_profile[headline]' => 'Developpeuse Symfony',
                'developer_profile[bio]' => 'Profil cree depuis un test fonctionnel.',
                'developer_profile[city]' => 'Lyon',
                'developer_profile[country]' => 'France',
                'developer_profile[locationType]' => 'remote',
                'developer_profile[experienceLevel]' => 'junior',
                'developer_profile[yearsExperience]' => '2',
            ]);
            $form['developer_profile[avatarFile]']->upload($imagePath);
            $client->submit($form);

            self::assertResponseRedirects('/applicant/profile/step-2');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            /** @var DeveloperProfileRepository $profiles */
            $profiles = static::getContainer()->get(DeveloperProfileRepository::class);
            $profile = $profiles->findOneBy(['user' => $users->findOneBy(['email' => $email])]);

            self::assertNotNull($profile);
            self::assertNotNull($profile->getAvatarPath());
            self::assertStringStartsWith('uploads/avatars/'.$profile->getSlug().'-', $profile->getAvatarPath());

            /** @var string $projectDir */
            $projectDir = static::getContainer()->getParameter('kernel.project_dir');
            self::assertFileExists($projectDir.'/public/'.$profile->getAvatarPath());

            $storedAvatarPath = $projectDir.'/public/'.$profile->getAvatarPath();
        } finally {
            $this->deleteFileIfExists($imagePath);
            $this->deleteFileIfExists($storedAvatarPath);
        }
    }

    public function testApplicantCannotCreateDeveloperProfileWithInvalidAvatarUpload(): void
    {
        $client = static::createClient();
        $email = sprintf('avatar_ko_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();

        $invalidFilePath = $this->createTemporaryTextFile();

        try {
            $form = $crawler->selectButton('Étape suivante')->form([
                'developer_profile[firstName]' => 'Mylene',
                'developer_profile[lastName]' => 'Martin',
                'developer_profile[headline]' => 'Developpeuse Symfony',
                'developer_profile[bio]' => 'Profil cree depuis un test fonctionnel.',
                'developer_profile[city]' => 'Lyon',
                'developer_profile[country]' => 'France',
                'developer_profile[locationType]' => 'remote',
                'developer_profile[experienceLevel]' => 'junior',
                'developer_profile[yearsExperience]' => '2',
            ]);
            $form['developer_profile[avatarFile]']->upload($invalidFilePath);
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'image valide.');

            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            /** @var DeveloperProfileRepository $profiles */
            $profiles = static::getContainer()->get(DeveloperProfileRepository::class);
            $profile = $profiles->findOneBy(['user' => $users->findOneBy(['email' => $email])]);

            self::assertNull($profile);
        } finally {
            $this->deleteFileIfExists($invalidFilePath);
        }
    }

    public function testApplicantCanContinueProfileCreationWhenProfileAlreadyExists(): void
    {
        $client = static::createClient();
        $email = sprintf('existing_profile_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithPreCreatedDeveloperProfile($email, $password);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $client->followRedirect();

        $crawler = $client->request('GET', '/applicant/profile/create');
        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $crawler->filter('#developer_profile_firstName')->attr('value'));
        self::assertSame('Durand', $crawler->filter('#developer_profile_lastName')->attr('value'));

        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Alice',
            'developer_profile[lastName]' => 'Durand',
            'developer_profile[headline]' => 'Développeuse Symfony',
            'developer_profile[bio]' => 'Profil initialisé depuis l inscription.',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'hybrid',
            'developer_profile[experienceLevel]' => 'junior',
            'developer_profile[yearsExperience]' => '3',
        ]));

        self::assertResponseRedirects('/applicant/profile/step-2');

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        /** @var DeveloperProfileRepository $profiles */
        $profiles = static::getContainer()->get(DeveloperProfileRepository::class);
        $profile = $profiles->findOneBy(['user' => $users->findOneBy(['email' => $email])]);

        self::assertNotNull($profile);
        self::assertSame('Développeuse Symfony', $profile->getHeadline());
        self::assertSame('Paris', $profile->getCity());
    }

    public function testApplicantStepRoutesRedirectToCreateWhenProfileDoesNotExist(): void
    {
        $client = static::createClient();
        $email = sprintf('steps_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $client->followRedirect();

        $client->request('GET', '/applicant/profile/step-2');
        self::assertResponseRedirects('/applicant/profile/create');

        $client->request('GET', '/applicant/profile/step-3');
        self::assertResponseRedirects('/applicant/profile/create');

        $client->request('GET', '/applicant/profile/step-4');
        self::assertResponseRedirects('/applicant/profile/create');
    }

    public function testApplicantDashboardShowsLockedNextStepButtonsBeforeStep1(): void
    {
        $client = static::createClient();
        $email = sprintf('locked_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $crawler = $client->followRedirect();

        self::assertSelectorExists('a[href="/applicant"]');
        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/applicant/profile/create"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-2"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-3"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-4"]');
    }

    public function testApplicantDashboardTreatsPreCreatedProfileAsToCreate(): void
    {
        $client = static::createClient();
        $email = sprintf('dashboard_precreated_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithPreCreatedDeveloperProfile($email, $password);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects('/applicant');
        $crawler = $client->followRedirect();

        self::assertSelectorExists('a[href="/applicant"]');
        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'À créer');
        self::assertSelectorTextContains('body', 'Créer mon profil');
        self::assertSelectorExists('a[href="/applicant/profile/create"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-2"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-3"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-4"]');
    }

    private function createUserWithStatus(string $email, string $plainPassword, UserStatus $status, array $roles = ['ROLE_USER']): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus($status);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createUserWithPreCreatedDeveloperProfile(string $email, string $plainPassword): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $profile = new DeveloperProfile();
        $profile->setFirstName('Alice');
        $profile->setLastName('Durand');
        $profile->setHeadline('');
        $profile->setIsPublic(false);
        $profile->setSlug('alice-durand-'.bin2hex(random_bytes(4)));
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($user);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createTemporaryPngFile(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avatar_png_'.bin2hex(random_bytes(8)).'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z0xQAAAAASUVORK5CYII=');
        if (false === $png) {
            throw new \RuntimeException('Impossible de gerer le contenu de l image de test.');
        }

        file_put_contents($path, $png);

        return $path;
    }

    private function createTemporaryTextFile(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avatar_txt_'.bin2hex(random_bytes(8)).'.txt';
        file_put_contents($path, 'not-an-image');

        return $path;
    }

    private function deleteFileIfExists(?string $path): void
    {
        if (\is_string($path) && is_file($path)) {
            unlink($path);
        }
    }

    private function ensureSchemaExists(EntityManagerInterface $entityManager): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::$schemaInitialized = true;
    }
}
