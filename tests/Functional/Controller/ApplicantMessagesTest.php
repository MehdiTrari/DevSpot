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
    public function testApplicantCanSeeReceivedMessagesOrderedFromNewestToOldest(): void
    {
        $client = static::createClient();
        $email = sprintf('messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);

        $olderConversation = $this->createConversationFromRecruiter(
            $user,
            'alice-list@example.com',
            'Alice',
            'Recruiter',
            'Premier message de test pour la liste.',
            false,
            new \DateTimeImmutable('-2 days')
        );
        $newerConversation = $this->createConversationFromRecruiter(
            $user,
            'bob-list@example.com',
            'Bob',
            'Recruiter',
            'Second message plus recent pour la liste.',
            false,
            new \DateTimeImmutable('-1 day')
        );

        $this->login($client, $email, $password);

        $crawler = $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Recruiter');
        self::assertSelectorTextContains('body', 'Bob Recruiter');
        self::assertSelectorTextContains('body', 'Premier message de test pour la liste.');
        self::assertSelectorTextContains('body', 'Second message plus recent pour la liste.');

        $links = $crawler->filter('a[href^="/applicant/messages/"]');
        self::assertGreaterThanOrEqual(2, $links->count());
        self::assertStringContainsString((string) $newerConversation->getRecruiterUser()?->getRecruiterProfile()?->getFirstName(), $links->eq(0)->text());
        self::assertStringContainsString((string) $olderConversation->getRecruiterUser()?->getRecruiterProfile()?->getFirstName(), $links->eq(1)->text());
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
        self::assertSelectorTextContains('body', 'Aucune conversation active.');
    }

    public function testMessagesPageShowsConversationWithoutUnreadBadgeWhenEverythingIsAlreadyRead(): void
    {
        $client = static::createClient();
        $email = sprintf('read_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);

        $conversation = $this->createConversationFromRecruiter(
            $user,
            'carla-read@example.com',
            'Carla',
            'Recruiter',
            'Message deja consulte par le candidat.',
            true,
            new \DateTimeImmutable('-3 hours')
        );

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Carla Recruiter');
        self::assertSelectorTextContains('body', 'Message deja consulte par le candidat.');
        self::assertSelectorNotExists(sprintf('a[href="/applicant/messages/%d"] span[class*="bg-emerald-500"]', $conversation->getId()));
    }

    public function testOpeningMessageShowsFullContentAndMarksItAsRead(): void
    {
        $client = static::createClient();
        $email = sprintf('show_message_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $user = $this->createApplicantWithProfile($email, $password);

        [$conversation, $message] = $this->createConversationWithMessageFromRecruiter(
            $user,
            'diane-show@example.com',
            'Diane',
            'Recruiter',
            "Bonjour,\nNous souhaitons vous proposer une mission freelance.",
            false,
            new \DateTimeImmutable('-2 hours')
        );

        $this->login($client, $email, $password);
        $client->request('GET', '/applicant/messages/'.$conversation->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Diane Recruiter');
        self::assertSelectorTextContains('body', 'diane-show@example.com');
        self::assertSelectorTextContains('body', 'Nous souhaitons vous proposer une mission freelance.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedMessage = $entityManager->getRepository(Message::class)->find($message->getId());
        self::assertInstanceOf(Message::class, $reloadedMessage);
        self::assertTrue((bool) $reloadedMessage->isRead());
    }

    public function testOnlyOwnerCanAccessMessageDetail(): void
    {
        $client = static::createClient();
        $ownerEmail = sprintf('owner_messages_%s@example.com', bin2hex(random_bytes(6)));
        $otherEmail = sprintf('other_messages_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';

        $owner = $this->createApplicantWithProfile($ownerEmail, $password);
        $this->createApplicantWithProfile($otherEmail, $password);

        [$conversation] = $this->createConversationWithMessageFromRecruiter(
            $owner,
            'eva-private@example.com',
            'Eva',
            'Recruiter',
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

        $this->createConversationFromRecruiter(
            $user,
            'franck-header@example.com',
            'Franck',
            'Recruiter',
            'Message pour verifier le badge non lu.',
            false,
            new \DateTimeImmutable('-30 minutes')
        );

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

    private function createRecruiterWithProfile(string $email, string $firstName, string $lastName): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, 'password123', ['ROLE_RECRUITER']);

        $profile = new RecruiterProfile();
        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setJobTitle('Talent Acquisition');
        $profile->setWorkEmail($email);
        $profile->setUser($user);
        $user->setRecruiterProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createConversationFromRecruiter(
        User $applicantUser,
        string $recruiterEmail,
        string $recruiterFirstName,
        string $recruiterLastName,
        string $messageContent,
        bool $isRead,
        \DateTimeImmutable $createdAt,
    ): Conversation {
        [$conversation] = $this->createConversationWithMessageFromRecruiter(
            $applicantUser,
            $recruiterEmail,
            $recruiterFirstName,
            $recruiterLastName,
            $messageContent,
            $isRead,
            $createdAt
        );

        return $conversation;
    }

    /**
     * @return array{0: Conversation, 1: Message}
     */
    private function createConversationWithMessageFromRecruiter(
        User $applicantUser,
        string $recruiterEmail,
        string $recruiterFirstName,
        string $recruiterLastName,
        string $messageContent,
        bool $isRead,
        \DateTimeImmutable $createdAt,
    ): array {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $recruiterUser = $this->createRecruiterWithProfile($recruiterEmail, $recruiterFirstName, $recruiterLastName);

        $conversation = new Conversation();
        $conversation->setApplicantUser($applicantUser);
        $conversation->setRecruiterUser($recruiterUser);
        $conversation->setSubject('Sujet de test');
        $conversation->setStatus(ConversationStatus::OPEN);
        $conversation->setCreatedAt($createdAt);
        $conversation->setUpdatedAt($createdAt);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSenderUser($recruiterUser);
        $message->setContent($messageContent);
        $message->setIsRead($isRead);
        $message->setCreatedAt($createdAt);

        $entityManager->persist($conversation);
        $entityManager->persist($message);
        $entityManager->flush();

        return [$conversation, $message];
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
