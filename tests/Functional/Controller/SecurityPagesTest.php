<?php
namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Enum\UserStatus;
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
        self::assertSelectorTextContains('body', 'Connecté en tant que recruteur');
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
        self::assertSelectorTextContains('body', 'Connecté en tant que recruteur');
        self::assertSelectorExists('a[href="/logout"]');

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(302);
        $client->followRedirect();
        self::assertSelectorTextNotContains('body', 'Connecté en tant que');
        self::assertSelectorNotExists('a[href="/logout"]');
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
            'registration_form[accountType]' => 'applicant',
            'registration_form[firstName]' => 'John',
            'registration_form[lastName]' => 'Doe',
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
            'registration_form[accountType]' => 'applicant',
            'registration_form[firstName]' => 'John',
            'registration_form[lastName]' => 'Doe',
        ]));
        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'Password123!',
            'registration_form[agreeTerms]' => 1,
            'registration_form[accountType]' => 'applicant',
            'registration_form[firstName]' => 'John',
            'registration_form[lastName]' => 'Doe',
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
            'registration_form[accountType]' => 'applicant',
            'registration_form[firstName]' => 'John',
            'registration_form[lastName]' => 'Doe',
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
            'registration_form[accountType]' => 'applicant',
            'registration_form[firstName]' => 'John',
            'registration_form[lastName]' => 'Doe',
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