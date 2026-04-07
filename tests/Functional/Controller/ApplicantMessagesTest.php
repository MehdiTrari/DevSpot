<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApplicantMessagesTest extends WebTestCase
{
    public function testApplicantCanSeeReceivedMessagesOrderedFromNewestToOldest(): void
    {
        $client = static::createClient();
        $email = sprintf('messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $profile = $user->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $olderMessage = $this->createContactMessage($profile, 'Alice Recruiter', 'alice@example.com', 'Sujet plus ancien', 'Premier message de test pour la liste.', false, new \DateTimeImmutable('-2 days'));
        $newerMessage = $this->createContactMessage($profile, 'Bob Recruiter', 'bob@example.com', 'Sujet plus recent', 'Second message de test plus recent.', false, new \DateTimeImmutable('-1 day'));

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Recruiter');
        self::assertSelectorTextContains('body', 'Bob Recruiter');
        self::assertSelectorTextContains('body', 'alice@example.com');
        self::assertSelectorTextContains('body', 'bob@example.com');
        self::assertSelectorTextContains('body', 'Sujet plus ancien');
        self::assertSelectorTextContains('body', 'Sujet plus recent');

        $articles = $crawler->filter('article');
        self::assertGreaterThanOrEqual(2, $articles->count());
        self::assertStringContainsString($newerMessage->getSubject() ?? '', $articles->eq(0)->text());
        self::assertStringContainsString($olderMessage->getSubject() ?? '', $articles->eq(1)->text());
    }

    public function testMessagesPageShowsEmptyStateWhenNoMessageExists(): void
    {
        $client = static::createClient();
        $email = sprintf('empty_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicantWithProfile($email, $password);

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Vous n\'avez reçu aucun message pour le moment.');
    }

    public function testMessagesPageShowsInfoWhenAllMessagesAreAlreadyOpened(): void
    {
        $client = static::createClient();
        $email = sprintf('read_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $profile = $user->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $this->createContactMessage($profile, 'Carla Recruiter', 'carla@example.com', 'Sujet lu', 'Message deja consulte par le candidat.', true, new \DateTimeImmutable('-3 hours'));

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun nouveau message. Vous pouvez toujours consulter vos messages déjà ouverts ci-dessous.');
        self::assertSelectorTextContains('body', 'Sujet lu');
    }

    public function testOpeningMessageShowsFullContentAndMarksItAsRead(): void
    {
        $client = static::createClient();
        $email = sprintf('show_message_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $profile = $user->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $message = $this->createContactMessage(
            $profile,
            'Diane Recruiter',
            'diane@example.com',
            'Proposition freelance',
            "Bonjour,\nNous souhaitons vous proposer une mission freelance.",
            false,
            new \DateTimeImmutable('-2 hours')
        );

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Diane Recruiter');
        self::assertSelectorTextContains('body', 'diane@example.com');
        self::assertSelectorTextContains('body', 'Proposition freelance');
        self::assertSelectorTextContains('body', 'Nous souhaitons vous proposer une mission freelance.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedMessage = $entityManager->getRepository(ContactMessage::class)->find($message->getId());
        self::assertInstanceOf(ContactMessage::class, $reloadedMessage);
        self::assertTrue((bool) $reloadedMessage->isRead());
    }

    public function testOnlyOwnerCanAccessMessageDetail(): void
    {
        $client = static::createClient();
        $ownerEmail = sprintf('owner_messages_%s@example.com', bin2hex(random_bytes(6)));
        $otherEmail = sprintf('other_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $owner = $this->createApplicantWithProfile($ownerEmail, $password);
        $otherUser = $this->createApplicantWithProfile($otherEmail, $password);
        $ownerProfile = $owner->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $ownerProfile);
        self::assertInstanceOf(DeveloperProfile::class, $otherUser->getDeveloperProfile());

        $message = $this->createContactMessage($ownerProfile, 'Eva Recruiter', 'eva@example.com', 'Sujet prive', 'Message reserve au proprietaire du profil.', false, new \DateTimeImmutable('-1 hour'));

        $this->login($client, $otherEmail, $password);
        $client->request('GET', '/applicant/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousUserCannotAccessMessagesPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/applicant/messages');

        self::assertResponseRedirects('/403');
    }

    public function testNonApplicantUserCannotAccessMessagesPage(): void
    {
        $client = static::createClient();
        $email = sprintf('recruiter_only_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createUser($email, $password, ['ROLE_USER']);

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseRedirects('/403');
    }

    public function testApplicantWithoutProfileIsRedirectedToProfileCreationWhenAccessingMessages(): void
    {
        $client = static::createClient();
        $email = sprintf('no_profile_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createUser($email, $password, ['ROLE_APPLICANT']);

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseRedirects('/applicant/profile/create');
    }

    public function testApplicantHeaderDisplaysMessagingEntryPoint(): void
    {
        $client = static::createClient();
        $email = sprintf('header_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $profile = $user->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $this->createContactMessage($profile, 'Franck Recruiter', 'franck@example.com', 'Sujet badge', 'Message pour verifier le badge non lu.', false, new \DateTimeImmutable('-30 minutes'));

        $this->login($client, $email, $password);
        $crawler = $client->request('GET', '/applicant');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/applicant/messages"]');
        self::assertStringContainsString('1', $crawler->filter('a[href="/applicant/messages"]')->text());
    }

    private function login(object $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $password,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function createApplicantWithProfile(string $email, string $password): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, $password, ['ROLE_APPLICANT']);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Test');
        $profile->setLastName('Applicant');
        $profile->setHeadline('Developpeur Symfony');
        $profile->setSlug('messages-'.bin2hex(random_bytes(5)));
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createUser(string $email, string $plainPassword, array $roles): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createContactMessage(
        DeveloperProfile $profile,
        string $recruiterName,
        string $recruiterEmail,
        string $subject,
        string $messageContent,
        bool $isRead,
        \DateTimeImmutable $createdAt,
    ): ContactMessage {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $message = new ContactMessage();
        $message->setRecruiterName($recruiterName);
        $message->setRecruiterEmail($recruiterEmail);
        $message->setSubject($subject);
        $message->setMessage($messageContent);
        $message->setIsRead($isRead);
        $message->setDeveloperProfile($profile);

        $createdAtProperty = new \ReflectionProperty(ContactMessage::class, 'createdAt');
        $createdAtProperty->setValue($message, $createdAt);

        $entityManager->persist($message);
        $entityManager->flush();

        return $message;
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