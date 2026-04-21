<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Company;
use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\JobOffer;
use App\Entity\Notification;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OfferStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApplicantNotificationsTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testRecruiterMessageCreatesApplicantNotificationVisibleOnNotificationsPage(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithProfile('Alice', 'Martin', true);
        $recruiter = $this->createRecruiter('nora_'.bin2hex(random_bytes(4)).'@example.com');
        $profile = $applicant->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/profil/'.$profile->getSlug());
        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact_message[recruiterName]' => 'Nora Recruiter',
            'contact_message[recruiterEmail]' => (string) $recruiter->getEmail(),
            'contact_message[subject]' => 'Mission Symfony',
            'contact_message[message]' => 'Bonjour, je souhaite échanger avec vous au sujet d\'une mission Symfony.',
        ]));

        self::assertResponseRedirects('/profil/'.$profile->getSlug());

        $notification = $this->findNotificationFor($applicant, NotificationType::NEW_MESSAGE);
        self::assertNotNull($notification);
        self::assertSame('Nouveau message reçu', $notification->getTitle());

        $client->loginUser($applicant);
        $client->request('GET', '/notifications');
        self::assertSelectorTextContains('body', 'Nouveau message reçu');
        self::assertSelectorTextContains('body', '1 notification(s) non lue(s)');
    }

    public function testRecruiterAddingFavoriteNotifiesApplicant(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithProfile('Camille', 'Durand', true);
        $recruiter = $this->createRecruiter('fav_'.bin2hex(random_bytes(4)).'@example.com');
        $profile = $applicant->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/profil/'.$profile->getSlug());
        $client->submit($crawler->filter(sprintf('form[action="/recruiter/favorites/%d/add"]', $profile->getId()))->first()->form());

        self::assertResponseRedirects('/profil/'.$profile->getSlug());

        $notification = $this->findNotificationFor($applicant, NotificationType::PROFILE_FAVORITED);
        self::assertNotNull($notification);
        self::assertStringContainsString('ajouté votre profil à ses favoris', (string) $notification->getContent());
    }

    public function testApplicantDashboardCreatesSingleIncompleteProfileReminder(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithoutProfile();

        $client->loginUser($applicant);
        $client->request('GET', '/applicant');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/applicant');
        self::assertResponseIsSuccessful();

        $notifications = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Notification::class)
            ->findBy([
                'user' => $applicant,
                'type' => NotificationType::PROFILE_INCOMPLETE,
            ]);

        self::assertCount(1, $notifications);
    }

    public function testExpiredOfferNotifiesApplicantsAlreadyInContactWithRecruiter(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithProfile('Theo', 'Bernard', true);
        $recruiter = $this->createRecruiter('expired_'.bin2hex(random_bytes(4)).'@example.com');
        $this->createConversation($applicant, $recruiter, 'Opportunité backend');
        $offer = $this->createOffer($recruiter, 'Backend PHP', new \DateTimeImmutable('-1 day'));

        $client->loginUser($applicant);
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        $notification = $this->findNotificationFor($applicant, NotificationType::JOB_OFFER_EXPIRED);
        self::assertNotNull($notification);
        self::assertStringContainsString('Backend PHP', (string) $notification->getContent());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloadedOffer = $entityManager->getRepository(JobOffer::class)->find($offer->getId());
        self::assertInstanceOf(JobOffer::class, $reloadedOffer);
        self::assertSame(OfferStatus::CLOSED, $reloadedOffer->getStatus());
    }

    public function testAdminCanModerateProfileAndApplicantReceivesNotification(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithProfile('Maya', 'Lopez', true);
        $admin = $this->createUser(sprintf('admin_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_ADMIN']);
        $profile = $applicant->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/profiles');
        $form = $crawler->filter(sprintf('form[action="/admin/profiles/%d/moderate"]', $profile->getId()))->first()->form([
            'reason' => 'Le profil doit être revu avant republication.',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/profiles');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloadedProfile = $entityManager->getRepository(DeveloperProfile::class)->find($profile->getId());
        self::assertInstanceOf(DeveloperProfile::class, $reloadedProfile);
        self::assertFalse($reloadedProfile->isPublic());
        self::assertNotNull($reloadedProfile->getModeratedAt());

        $notification = $this->findNotificationFor($applicant, NotificationType::PROFILE_MODERATED);
        self::assertNotNull($notification);
        self::assertStringContainsString('revu avant republication', (string) $notification->getContent());

        $client->loginUser($applicant);
        $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ouvrir le message');
        self::assertSelectorExists('[data-notification-message-modal="true"]');
    }

    private function createApplicantWithProfile(string $firstName, string $lastName, bool $isPublic): User
    {
        $user = $this->createUser(sprintf('applicant_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_APPLICANT']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $profile = new DeveloperProfile();
        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setHeadline('Développeur Symfony');
        $profile->setSlug(strtolower($firstName.'-'.$lastName.'-'.bin2hex(random_bytes(4))));
        $profile->setBio('Profil applicant de test.');
        $profile->setCity('Paris');
        $profile->setCountry('France');
        $profile->setIsPublic($isPublic);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createApplicantWithoutProfile(): User
    {
        return $this->createUser(sprintf('incomplete_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_APPLICANT']);
    }

    private function createRecruiter(string $email): User
    {
        $user = $this->createUser($email, ['ROLE_RECRUITER']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $company = new Company();
        $company->setName('Company '.bin2hex(random_bytes(3)));
        $entityManager->persist($company);

        $profile = new RecruiterProfile();
        $profile->setFirstName('Nora');
        $profile->setLastName('Recruiter');
        $profile->setJobTitle('Talent Acquisition');
        $profile->setWorkEmail($email);
        $profile->setCompany($company);
        $profile->setUser($user);
        $user->setRecruiterProfile($profile);

        $entityManager->persist($profile);
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

        $entityManager->persist($conversation);
        $entityManager->flush();

        return $conversation;
    }

    private function createOffer(User $recruiter, string $title, \DateTimeImmutable $deadline): JobOffer
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $offer = new JobOffer();
        $offer->setRecruiterProfile($recruiter->getRecruiterProfile());
        $offer->setTitle($title);
        $offer->setDescription('Offre de test expirée.');
        $offer->setStatus(OfferStatus::PUBLISHED);
        $offer->setApplicationDeadline($deadline);

        $entityManager->persist($offer);
        $entityManager->flush();

        return $offer;
    }

    private function createUser(string $email, array $roles): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function findNotificationFor(User $user, NotificationType $type): ?Notification
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Notification::class)
            ->findOneBy([
                'user' => $user,
                'type' => $type,
            ], ['id' => 'DESC']);
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
