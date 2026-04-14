<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileContactFormTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testAnonymousUserSeesLoginPromptInsteadOfContactFormOnPublicProfile(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true)->getDeveloperProfile();

        $client->request('GET', '/profil/'.$profile->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#contact-form');
        self::assertSelectorTextContains('body', 'Vous devez vous connecter pour entrer en contact avec ce développeur.');
        self::assertSelectorExists('a[href="/login"]');
    }

    public function testAuthenticatedUserCanSeeContactFormOnPublicProfile(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true)->getDeveloperProfile();
        $email = sprintf('recruiter_visible_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $this->createRecruiterUser($email, $password);
        $this->login($client, $email, $password);

        $client->request('GET', '/profil/'.$profile->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#contact-form');
        self::assertSelectorExists('input[name="contact_message[recruiterName]"]');
        self::assertSelectorExists('input[name="contact_message[recruiterEmail]"]');
        self::assertSelectorExists('input[name="contact_message[subject]"]');
        self::assertSelectorExists('textarea[name="contact_message[message]"]');
    }

    public function testContactFormSubmissionCreatesConversationAndShowsConfirmation(): void
    {
        $client = static::createClient();
        $profileOwner = $this->createProfileOwnerWithPortfolio(true);
        $profile = $profileOwner->getDeveloperProfile();
        $email = sprintf('recruiter_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $recruiter = $this->createRecruiterUser($email, $password);
        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/profil/'.$profile->getSlug());
        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact_message[recruiterName]' => 'Mylene Recruiter',
            'contact_message[recruiterEmail]' => $email,
            'contact_message[subject]' => 'Opportunite PHP',
            'contact_message[message]' => 'Bonjour, nous avons une opportunite CDI Symfony pour vous.',
        ]));

        self::assertResponseRedirects('/profil/'.$profile->getSlug());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Votre message a bien été envoyé au développeur.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $conversation = $entityManager->getRepository(Conversation::class)->findOneBy([
            'applicantUser' => $profileOwner,
            'recruiterUser' => $recruiter,
        ]);
        self::assertNotNull($conversation);
        self::assertSame('Opportunite PHP', $conversation->getSubject());

        $savedMessage = $entityManager->getRepository(Message::class)->findOneBy([
            'conversation' => $conversation,
            'senderUser' => $recruiter,
        ]);

        self::assertNotNull($savedMessage);
        self::assertStringContainsString('Nom du recruteur: Mylene Recruiter', (string) $savedMessage->getContent());
        self::assertStringContainsString('Email du recruteur: '.$email, (string) $savedMessage->getContent());
        self::assertStringContainsString('Bonjour, nous avons une opportunite CDI Symfony pour vous.', (string) $savedMessage->getContent());
        self::assertFalse((bool) $savedMessage->isRead());
    }

    public function testContactFormRejectsInvalidEmail(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true)->getDeveloperProfile();
        $email = sprintf('recruiter_invalid_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $this->createRecruiterUser($email, $password);
        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/profil/'.$profile->getSlug());
        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact_message[recruiterName]' => 'Recruiter',
            'contact_message[recruiterEmail]' => 'email-invalide',
            'contact_message[subject]' => 'Sujet test',
            'contact_message[message]' => 'Bonjour, ceci est un message de test valide.',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Merci de saisir un email valide.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $savedConversations = $entityManager->getRepository(Conversation::class)->findBy([
            'applicantUser' => $profile->getUser(),
        ]);

        self::assertCount(0, $savedConversations);
    }

    public function testPrivateProfileDoesNotDisplayContactFormForOwner(): void
    {
        $client = static::createClient();
        $email = sprintf('owner_private_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createProfileOwnerWithPortfolio(false, $email, $password);

        $this->login($client, $email, $password);

        $client->request('GET', '/profil/'.$user->getDeveloperProfile()?->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#contact-form');
        self::assertSelectorTextNotContains('body', 'Envoyer le message');
    }

    public function testCannotSendMessageToPrivateProfile(): void
    {
        $client = static::createClient();
        $email = sprintf('owner_post_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createProfileOwnerWithPortfolio(false, $email, $password);

        $this->login($client, $email, $password);

        $client->request('POST', '/profil/'.$user->getDeveloperProfile()?->getSlug(), [
            'contact_message' => [
                'recruiterName' => 'X',
                'recruiterEmail' => 'x@example.com',
                'subject' => '',
                'message' => 'Message non autorise pour profil prive.',
            ],
        ]);

        self::assertResponseRedirects('/403');
    }

    public function testContactFormRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true)->getDeveloperProfile();
        $email = sprintf('recruiter_csrf_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $this->createRecruiterUser($email, $password);
        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/profil/'.$profile->getSlug());
        $form = $crawler->selectButton('Envoyer le message')->form([
            'contact_message[recruiterName]' => 'Recruiter',
            'contact_message[recruiterEmail]' => 'recruiter@example.com',
            'contact_message[subject]' => 'Sujet',
            'contact_message[message]' => 'Bonjour, ceci est un message valide en contenu.',
        ]);
        $form['contact_message[_token]'] = 'invalid-token';

        $client->submit($form);

        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $savedConversations = $entityManager->getRepository(Conversation::class)->findBy([
            'applicantUser' => $profile->getUser(),
        ]);

        self::assertCount(0, $savedConversations);
    }

    public function testAnonymousUserCannotSubmitContactForm(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true)->getDeveloperProfile();

        $client->request('POST', '/profil/'.$profile->getSlug(), [
            'contact_message' => [
                'recruiterName' => 'Recruiter',
                'recruiterEmail' => 'recruiter@example.com',
                'subject' => 'Sujet',
                'message' => 'Bonjour, ceci est un message qui ne doit pas etre accepte.',
            ],
        ]);

        self::assertResponseRedirects('/403');
    }

    private function login($client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function createProfileOwnerWithPortfolio(
        bool $isPublic,
        ?string $email = null,
        string $password = 'password123',
    ): User {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email ?? sprintf('applicant_%s@example.com', bin2hex(random_bytes(6))));
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $profile = new DeveloperProfile();
        $profile->setFirstName('Test');
        $profile->setLastName('Contact');
        $profile->setHeadline('Developpeur Symfony');
        $profile->setSlug('contact-'.bin2hex(random_bytes(5)));
        $profile->setIsPublic($isPublic);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($user);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createRecruiterUser(string $email, string $password): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_RECRUITER']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
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
