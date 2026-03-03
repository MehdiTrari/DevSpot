<?php
namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\DeveloperProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityPagesTest extends WebTestCase
{
    public function testLoginPageIsSuccessful(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connexion');
        self::assertSame('Se connecter', trim($crawler->filter('button[type="submit"]')->text()));
    }

    public function testRegisterPageIsSuccessful(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'compte');
        self::assertStringContainsString('compte', trim($crawler->filter('button[type="submit"]')->text()));
    }

    public function testLoginFormAuthenticatesActiveUser(): void
    {
        $client = static::createClient();
        $email = sprintf('login_ok_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createActiveUser($email, $password);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', $email);
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function testApplicantIsRedirectedToApplicantHomeAfterLogin(): void
    {
        $client = static::createClient();
        $email = sprintf('applicant_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::ACTIVE, ['ROLE_APPLICANT']);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseRedirects('/');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bienvenue sur DevSpot');
        self::assertSelectorExists('a[href="/applicant"]');
    }

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
        self::assertResponseRedirects('/');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');

        $client->submit($crawler->selectButton('Enregistrer mon profil')->form([
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
        self::assertResponseRedirects('/');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();

        $imagePath = $this->createTemporaryPngFile();
        $storedAvatarPath = null;

        try {
            $form = $crawler->selectButton('Enregistrer mon profil')->form([
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
        self::assertResponseRedirects('/');
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->filter('a[href="/applicant/profile/create"]')->link());
        self::assertResponseIsSuccessful();

        $invalidFilePath = $this->createTemporaryTextFile();

        try {
            $form = $crawler->selectButton('Enregistrer mon profil')->form([
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
        self::assertResponseRedirects('/');
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
        self::assertResponseRedirects('/');
        $crawler = $client->followRedirect();

        self::assertSelectorExists('a[href="/applicant"]');
        $crawler = $client->click($crawler->filter('a[href="/applicant"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/applicant/profile/create"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-2"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-3"]');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-4"]');
    }

    public function testLoginFormShowsErrorWithWrongPassword(): void
    {
        $client = static::createClient();
        $email = sprintf('login_ko_%s@example.com', bin2hex(random_bytes(8)));
        $this->createActiveUser($email, 'password123');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => 'wrong-password',
        ]));

        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($email, $crawler->filter('#inputEmail')->attr('value'));
        self::assertSelectorTextContains('body', 'Invalid credentials.');
    }
    public function testLogoutDisconnectsAuthenticatedUser(): void
    {
        $client = static::createClient();
        $email = sprintf('logout_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createActiveUser($email, $password);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', $email);
        self::assertSelectorExists('a[href="/logout"]');

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(302);
        $client->followRedirect();
        self::assertSelectorTextNotContains('body', $email);
        self::assertSelectorTextNotContains('body', $email);
    }

    public function testPendingUserCannotLogin(): void
    {
        $client = static::createClient();
        $email = sprintf('pending_%s@example.com', bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, UserStatus::PENDING);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($email, $crawler->filter('#inputEmail')->attr('value'));
        self::assertSelectorTextContains('body', 'Votre compte est en attente de validation.');
    }

    public function testSuspendedUserCannotLogin(): void
    {
        $this->assertUserWithStatusCannotLogin(UserStatus::SUSPENDED);
    }

    public function testBannedUserCannotLogin(): void
    {
        $this->assertUserWithStatusCannotLogin(UserStatus::BANNED);
    }

    public function testDeletedUserCannotLogin(): void
    {
        $this->assertUserWithStatusCannotLogin(UserStatus::DELETED);
    }

    public function testRegisterFormCreatesUserAndRedirectsToLogin(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        $email = sprintf('test_%s@example.com', bin2hex(random_bytes(8)));

        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'Password123!',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Connexion');

        /** @var UserRepository $repository */
        $repository = static::getContainer()->get(UserRepository::class);
        $user = $repository->findOneBy(['email' => $email]);

        self::assertNotNull($user);
        self::assertContains('ROLE_APPLICANT', $user->getRoles());
        self::assertFalse($user->isVerified());
    }

    public function testRegisterFormShowsErrorWhenEmailAlreadyExists(): void
    {
        $client = static::createClient();
        $email = sprintf('duplicate_%s@example.com', bin2hex(random_bytes(8)));

        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'Password123!',
            'registration_form[agreeTerms]' => 1,
        ]));
        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'Password123!',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'There is already an account with this email');
    }

    public function testRegisterFormShowsErrorWhenPasswordIsTooShort(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => sprintf('short_%s@example.com', bin2hex(random_bytes(8))),
            'registration_form[plainPassword]' => '123',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe doit contenir au moins 8');
    }

    public function testRegisterFormShowsErrorWhenPasswordIsNotComplexEnough(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => sprintf('weak_%s@example.com', bin2hex(random_bytes(8))),
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe doit contenir au moins 8');
    }
    private function createActiveUser(string $email, string $plainPassword): User
    {
        return $this->createUserWithStatus($email, $plainPassword, UserStatus::ACTIVE);
    }

    private function assertUserWithStatusCannotLogin(UserStatus $status): void
    {
        $client = static::createClient();
        $email = sprintf('%s_%s@example.com', strtolower($status->name), bin2hex(random_bytes(8)));
        $password = 'password123';
        $this->createUserWithStatus($email, $password, $status);

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($email, $crawler->filter('#inputEmail')->attr('value'));
        self::assertSelectorTextContains('body', 'Votre compte ne peut pas se connecter.');
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
        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if ($schemaManager->tablesExist(['user'])) {
            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($metadata);
    }
}






