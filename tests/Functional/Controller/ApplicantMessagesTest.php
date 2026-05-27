<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\Message;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApplicantMessagesTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testApplicantCanSeeReceivedMessagesOrderedFromNewestToOldest(): void
    {
        $client = static::createClient();
        $email = sprintf('messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);

        $olderRecruiter = $this->createRecruiterWithProfile('alice_'.bin2hex(random_bytes(4)).'@example.com', 'Alice', 'Recruiter');
        $newerRecruiter = $this->createRecruiterWithProfile('bob_'.bin2hex(random_bytes(4)).'@example.com', 'Bob', 'Recruiter');

        $olderConversation = $this->createConversation(
            $user,
            $olderRecruiter,
            'Premier message de test pour la liste.',
            false,
            new \DateTimeImmutable('-2 days')
        );
        $newerConversation = $this->createConversation(
            $user,
            $newerRecruiter,
            'Second message de test plus recent.',
            false,
            new \DateTimeImmutable('-1 day')
        );

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Recruiter');
        self::assertSelectorTextContains('body', 'Bob Recruiter');
        self::assertSelectorTextContains('body', 'Premier message de test pour la liste.');
        self::assertSelectorTextContains('body', 'Second message de test plus recent.');

        $links = $crawler->filter('a[href^="/applicant/messages/"]');
        self::assertGreaterThanOrEqual(2, $links->count());
        self::assertStringContainsString((string) $newerConversation->getId(), (string) $links->eq(0)->attr('href'));
        self::assertStringContainsString('Bob Recruiter', $links->eq(0)->text());
        self::assertStringContainsString((string) $olderConversation->getId(), (string) $links->eq(1)->attr('href'));
        self::assertStringContainsString('Alice Recruiter', $links->eq(1)->text());
    }

    public function testMessagesPageShowsEmptyStateWhenNoConversationExists(): void
    {
        $client = static::createClient();
        $email = sprintf('empty_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicantWithProfile($email, $password);

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune conversation active.');
        self::assertSelectorTextContains('body', 'Sélectionnez une conversation à gauche.');
    }

    public function testMessagesPageShowsConversationWithoutUnreadBadgeWhenAllMessagesAreAlreadyOpened(): void
    {
        $client = static::createClient();
        $email = sprintf('read_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $recruiter = $this->createRecruiterWithProfile('carla_'.bin2hex(random_bytes(4)).'@example.com', 'Carla', 'Recruiter');

        $conversation = $this->createConversation(
            $user,
            $recruiter,
            'Message deja consulte par le candidat.',
            true,
            new \DateTimeImmutable('-3 hours')
        );

        $this->login($client, $email, $password);
        $crawler = $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Carla Recruiter');
        self::assertSelectorTextContains('body', 'Message deja consulte par le candidat.');
        self::assertCount(
            0,
            $crawler->filter(sprintf('a[href="/applicant/messages/%d"] span.inline-flex.min-h-5.min-w-5', $conversation->getId()))
        );
    }

    public function testOpeningConversationShowsFullContentAndMarksMessageAsRead(): void
    {
        $client = static::createClient();
        $email = sprintf('show_message_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $recruiter = $this->createRecruiterWithProfile('diane_'.bin2hex(random_bytes(4)).'@example.com', 'Diane', 'Recruiter');

        $conversation = $this->createConversation(
            $user,
            $recruiter,
            "Bonjour,\nNous souhaitons vous proposer une mission freelance.",
            false,
            new \DateTimeImmutable('-2 hours')
        );

        $messages = $conversation->getMessages()->toArray();
        self::assertCount(1, $messages);
        $message = $messages[0];
        self::assertInstanceOf(Message::class, $message);

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages/'.$conversation->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Diane Recruiter');
        self::assertSelectorTextContains('body', 'Nous souhaitons vous proposer une mission freelance.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedMessage = $entityManager->getRepository(Message::class)->find($message->getId());
        self::assertInstanceOf(Message::class, $reloadedMessage);
        self::assertTrue((bool) $reloadedMessage->isRead());
    }

    public function testConversationDetailRegistersMercureSubscription(): void
    {
        $client = static::createClient();
        $email = sprintf('mercure_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);
        $recruiter = $this->createRecruiterWithProfile('mercure_recruiter_'.bin2hex(random_bytes(4)).'@example.com', 'Mona', 'Recruiter');

        $conversation = $this->createConversation(
            $user,
            $recruiter,
            'Message test pour la souscription Mercure.',
            false,
            new \DateTimeImmutable('-45 minutes')
        );

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages/'.$conversation->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-chat-mercure-url]');
    }

    public function testOnlyOwnerCanAccessConversationDetail(): void
    {
        $client = static::createClient();
        $ownerEmail = sprintf('owner_messages_%s@example.com', bin2hex(random_bytes(6)));
        $otherEmail = sprintf('other_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $owner = $this->createApplicantWithProfile($ownerEmail, $password);
        $otherUser = $this->createApplicantWithProfile($otherEmail, $password);
        $recruiter = $this->createRecruiterWithProfile('eva_'.bin2hex(random_bytes(4)).'@example.com', 'Eva', 'Recruiter');

        self::assertInstanceOf(DeveloperProfile::class, $owner->getDeveloperProfile());
        self::assertInstanceOf(DeveloperProfile::class, $otherUser->getDeveloperProfile());

        $conversation = $this->createConversation(
            $owner,
            $recruiter,
            'Message reserve au proprietaire du profil.',
            false,
            new \DateTimeImmutable('-1 hour')
        );

        $this->login($client, $otherEmail, $password);
        $client->request('GET', '/applicant/messages/'.$conversation->getId());

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
        $recruiter = $this->createRecruiterWithProfile('franck_'.bin2hex(random_bytes(4)).'@example.com', 'Franck', 'Recruiter');

        $this->createConversation(
            $user,
            $recruiter,
            'Message pour verifier le badge non lu.',
            false,
            new \DateTimeImmutable('-30 minutes')
        );

        $this->login($client, $email, $password);
        $crawler = $client->request('GET', '/applicant');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/applicant/messages"]');
        self::assertSame('1', trim($crawler->filter('a[href="/applicant/messages"] span')->last()->text()));
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

    private function createRecruiterWithProfile(string $email, string $firstName, string $lastName): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, 'password123', ['ROLE_RECRUITER']);

        $recruiterProfile = new RecruiterProfile();
        $recruiterProfile->setFirstName($firstName);
        $recruiterProfile->setLastName($lastName);
        $recruiterProfile->setJobTitle('');
        $recruiterProfile->setWorkEmail($email);
        $recruiterProfile->setUser($user);
        $user->setRecruiterProfile($recruiterProfile);

        $entityManager->persist($recruiterProfile);
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

    private function createConversation(
        User $applicant,
        User $recruiter,
        string $messageContent,
        bool $isRead,
        \DateTimeImmutable $createdAt,
    ): Conversation {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $conversation = new Conversation();
        $conversation->setApplicantUser($applicant);
        $conversation->setRecruiterUser($recruiter);
        $conversation->setSubject('Conversation de test');
        $conversation->setStatus(ConversationStatus::OPEN);
        $conversation->setCreatedAt($createdAt);
        $conversation->setUpdatedAt($createdAt);

        $message = new Message();
        $conversation->addMessage($message);
        $message->setSenderUser($recruiter);
        $message->setContent($messageContent);
        $message->setIsRead($isRead);
        $message->setCreatedAt($createdAt);

        $entityManager->persist($conversation);
        $entityManager->persist($message);
        $entityManager->flush();

        return $conversation;
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
