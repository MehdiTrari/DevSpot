<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\FavoriteProfile;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecruiterNotificationsTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testAdminValidationCreatesRecruiterAcceptanceNotification(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(sprintf('admin_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_ADMIN']);
        $recruiter = $this->createRecruiter(sprintf('pending_recruiter_%s@example.com', bin2hex(random_bytes(6))), false, UserStatus::PENDING);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/users/%d/validate"] input[name="_token"]', $recruiter->getId()))
            ->attr('value');

        self::assertNotNull($token);

        $client->request('POST', '/admin/users/'.$recruiter->getId().'/validate', [
            '_token' => (string) $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        $notification = $this->findLatestNotificationFor($recruiter, NotificationType::ACCOUNT_APPROVED);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame('Votre compte a été accepté', $notification->getTitle());
    }

    public function testRecruiterReceivesNotificationWhenApplicantSendsMessageInConversation(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiter(sprintf('recruiter_msg_%s@example.com', bin2hex(random_bytes(6))), true);
        $applicant = $this->createApplicantWithProfile(sprintf('applicant_msg_%s@example.com', bin2hex(random_bytes(6))));
        $conversation = $this->createConversation($applicant, $recruiter, 'Mission Symfony');

        $client->loginUser($applicant);
        $crawler = $client->request('GET', '/applicant/messages/'.$conversation->getId());
        $client->submit($crawler->filter('form#chat-form')->form([
            'chat_reply[content]' => 'Bonjour, je suis disponible pour échanger cette semaine.',
        ]));

        self::assertResponseRedirects('/applicant/messages/'.$conversation->getId());

        $notification = $this->findLatestNotificationFor($recruiter, NotificationType::NEW_MESSAGE);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame('Nouveau message reçu', $notification->getTitle());
        self::assertSame('/recruiter/messages/'.$conversation->getId(), $notification->getLink());
    }

    public function testRecruiterDashboardCreatesIncompleteProfileReminderAndRemindsAgainAfterRead(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiter(sprintf('recruiter_incomplete_%s@example.com', bin2hex(random_bytes(6))), false);

        $client->loginUser($recruiter);
        $client->request('GET', '/recruiter');
        self::assertResponseIsSuccessful();

        $firstReminder = $this->findLatestNotificationFor($recruiter, NotificationType::PROFILE_INCOMPLETE);
        self::assertInstanceOf(Notification::class, $firstReminder);
        self::assertStringContainsString('profil recruteur est incomplet', (string) $firstReminder->getContent());
        self::assertFalse((bool) $firstReminder->isRead());

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $firstReminder->markAsRead();
        $entityManager->flush();

        $client->request('GET', '/recruiter');
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $notifications = $entityManager->getRepository(Notification::class)->findBy([
            'user' => $recruiter,
            'type' => NotificationType::PROFILE_INCOMPLETE,
        ], ['id' => 'DESC']);

        self::assertCount(2, $notifications);
        self::assertFalse((bool) $notifications[0]->isRead());
    }

    public function testRecruiterIsNotifiedWhenFavoriteApplicantProfileIsUpdated(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiter(sprintf('recruiter_follow_%s@example.com', bin2hex(random_bytes(6))), true);
        $applicant = $this->createApplicantWithProfile(sprintf('applicant_follow_%s@example.com', bin2hex(random_bytes(6))));
        $this->createFavorite($recruiter, $applicant->getDeveloperProfile());

        $client->loginUser($applicant);
        $crawler = $client->request('GET', '/applicant/profile/step-1');
        $client->submit($crawler->selectButton('Étape suivante')->form([
            'developer_profile[firstName]' => 'Alice',
            'developer_profile[lastName]' => 'Martin',
            'developer_profile[headline]' => 'Lead Symfony / API Platform',
            'developer_profile[bio]' => 'Profil mis à jour depuis un test fonctionnel.',
            'developer_profile[city]' => 'Paris',
            'developer_profile[country]' => 'France',
            'developer_profile[locationType]' => 'remote',
            'developer_profile[experienceLevel]' => 'senior',
            'developer_profile[yearsExperience]' => '6',
        ]));

        self::assertResponseRedirects('/applicant/profile/step-2');

        $notification = $this->findLatestNotificationFor($recruiter, NotificationType::PROFILE_UPDATED);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertStringContainsString('Alice Martin', (string) $notification->getContent());
    }

    public function testRecruiterIsNotifiedWhenFavoriteApplicantProfileBecomesPrivate(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiter(sprintf('recruiter_private_%s@example.com', bin2hex(random_bytes(6))), true);
        $applicant = $this->createApplicantWithProfile(sprintf('applicant_private_%s@example.com', bin2hex(random_bytes(6))));
        $this->createFavorite($recruiter, $applicant->getDeveloperProfile());

        $client->loginUser($applicant);
        $crawler = $client->request('GET', '/applicant');
        $client->submit($crawler->filter('form[action="/applicant/profile/visibility"]')->form([
            'is_public' => '0',
        ]));

        self::assertResponseRedirects('/applicant');

        $notification = $this->findLatestNotificationFor($recruiter, NotificationType::PROFILE_UNPUBLISHED);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertStringContainsString('désormais privé', (string) $notification->getContent());
    }

    private function createApplicantWithProfile(string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, ['ROLE_APPLICANT']);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Alice');
        $profile->setLastName('Martin');
        $profile->setHeadline('Développeuse Symfony');
        $profile->setBio('Profil applicant de test.');
        $profile->setCity('Paris');
        $profile->setCountry('France');
        $profile->setLocationType(LocationType::REMOTE);
        $profile->setExperienceLevel(ExperienceLevel::SENIOR);
        $profile->setYearsExperience(5);
        $profile->setSlug('recruiter-notif-'.bin2hex(random_bytes(5)));
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createRecruiter(string $email, bool $isComplete, UserStatus $status = UserStatus::ACTIVE): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, ['ROLE_RECRUITER'], $status);

        $company = new Company();
        $company->setName('Company '.bin2hex(random_bytes(4)));
        $entityManager->persist($company);

        $recruiterProfile = new RecruiterProfile();
        $recruiterProfile->setFirstName('Nora');
        $recruiterProfile->setLastName('Recruiter');
        $recruiterProfile->setJobTitle($isComplete ? 'Talent Acquisition Manager' : '');
        $recruiterProfile->setWorkEmail($email);
        $recruiterProfile->setCompany($company);
        $recruiterProfile->setUser($user);
        $user->setRecruiterProfile($recruiterProfile);

        $entityManager->persist($recruiterProfile);
        $entityManager->flush();

        return $user;
    }

    private function createConversation(User $applicant, User $recruiter, string $subject): Conversation
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $conversation = new Conversation();
        $conversation->setApplicantUser($applicant);
        $conversation->setRecruiterUser($recruiter);
        $conversation->setSubject($subject);
        $conversation->setStatus(ConversationStatus::OPEN);
        $conversation->setUpdatedAt(new \DateTimeImmutable('-1 hour'));

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSenderUser($recruiter);
        $message->setContent('Bonjour, avez-vous quelques disponibilités cette semaine ?');
        $message->setIsRead(false);
        $message->setCreatedAt(new \DateTimeImmutable('-1 hour'));

        $entityManager->persist($conversation);
        $entityManager->persist($message);
        $entityManager->flush();

        return $conversation;
    }

    private function createFavorite(User $recruiter, DeveloperProfile $profile): FavoriteProfile
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $favorite = new FavoriteProfile();
        $favorite->setRecruiterProfile($recruiter->getRecruiterProfile());
        $favorite->setDeveloperProfile($profile);

        $entityManager->persist($favorite);
        $entityManager->flush();

        return $favorite;
    }

    private function findLatestNotificationFor(User $user, NotificationType $type): ?Notification
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(Notification::class)->findOneBy([
            'user' => $user,
            'type' => $type,
        ], ['id' => 'DESC']);
    }

    private function createUser(string $email, array $roles, UserStatus $status = UserStatus::ACTIVE): User
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
        $user->setIsVerified(UserStatus::ACTIVE === $status);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

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
            throw new \RuntimeException('Aucune métadonnée Doctrine disponible pour créer le schéma de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        self::$schemaInitialized = true;
    }
}
