<?php

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        self::assertSelectorTextContains('h1', 'Creer un compte');
        self::assertSame('Creer mon compte', trim($crawler->filter('button[type="submit"]')->text()));
    }

    public function testRegisterFormCreatesUserAndRedirectsToLogin(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        $email = sprintf('test_%s@example.com', bin2hex(random_bytes(8)));

        $client->submit($crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
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
        $client->submit($crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
        ]));
        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'There is already an account with this email');
    }

    public function testRegisterFormShowsErrorWhenPasswordIsTooShort(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($crawler->selectButton('Creer mon compte')->form([
            'registration_form[email]' => sprintf('short_%s@example.com', bin2hex(random_bytes(8))),
            'registration_form[plainPassword]' => '123',
            'registration_form[agreeTerms]' => 1,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Your password should be at least 6 characters');
    }
}
