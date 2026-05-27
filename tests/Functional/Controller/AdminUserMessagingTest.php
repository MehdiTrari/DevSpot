<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserMessagingTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testAdminCanSendMessageToUserAndRecipientSeesItInNotifications(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin_message_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $recipient = $this->createApplicantWithProfile('recipient_'.bin2hex(random_bytes(4)).'@example.com');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/messages/compose?recipient='.$recipient->getId());

        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'admin_user_message[recipients]' => [(string) $recipient->getId()],
            'admin_user_message[title]' => 'Information importante',
            'admin_user_message[content]' => 'Votre dossier a bien ete pris en compte.',
        ]));

        self::assertResponseRedirects('/admin');

        $notification = $this->findNotificationFor($recipient, NotificationType::ADMIN_MESSAGE);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame('Information importante', $notification->getTitle());
        self::assertSame('Votre dossier a bien ete pris en compte.', $notification->getContent());
        self::assertSame($admin->getId(), $notification->getSenderUser()?->getId());
        self::assertSame($admin->getEmail(), $notification->getSenderDisplayName());

        $client->loginUser($recipient);
        $client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Information importante');
        self::assertSelectorTextContains('body', 'Votre dossier a bien ete pris en compte.');
        self::assertSelectorTextContains('body', 'Envoye par');
        self::assertSelectorTextContains('body', (string) $admin->getEmail());
    }

    public function testAdminCanSendMessageToMultipleUsers(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin_multi_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $firstRecipient = $this->createApplicantWithProfile('recipient_a_'.bin2hex(random_bytes(4)).'@example.com');
        $secondRecipient = $this->createApplicantWithProfile('recipient_b_'.bin2hex(random_bytes(4)).'@example.com');
        $otherUser = $this->createApplicantWithProfile('recipient_c_'.bin2hex(random_bytes(4)).'@example.com');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/messages/compose');
        $csrfToken = $crawler->filter('input[name="admin_user_message[_token]"]')->attr('value');

        self::assertNotNull($csrfToken);

        $client->request('POST', '/admin/users/messages/compose', [
            'admin_user_message' => [
                'recipients' => [(string) $firstRecipient->getId(), (string) $secondRecipient->getId()],
                'title' => 'Maintenance',
                'content' => 'Une operation de maintenance est prevue ce soir.',
                '_token' => $csrfToken,
            ],
        ]);

        self::assertResponseRedirects('/admin');

        self::assertNotNull($this->findNotificationFor($firstRecipient, NotificationType::ADMIN_MESSAGE));
        self::assertNotNull($this->findNotificationFor($secondRecipient, NotificationType::ADMIN_MESSAGE));
        self::assertNull($this->findNotificationFor($otherUser, NotificationType::ADMIN_MESSAGE));
    }

    public function testAdminMessageFormRejectsEmptyRequiredFields(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin_required_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $beforeCount = count($this->findNotificationsForType(NotificationType::ADMIN_MESSAGE));

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/messages/compose');
        $csrfToken = $crawler->filter('input[name="admin_user_message[_token]"]')->attr('value');

        self::assertNotNull($csrfToken);

        $client->request('POST', '/admin/users/messages/compose', [
            'admin_user_message' => [
                'recipients' => [],
                'title' => '',
                'content' => '',
                '_token' => $csrfToken,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Sélectionnez au moins un destinataire.');
        self::assertSelectorTextContains('body', 'Merci de renseigner un objet.');
        self::assertSelectorTextContains('body', 'Merci de renseigner un contenu de message.');
        self::assertCount($beforeCount, $this->findNotificationsForType(NotificationType::ADMIN_MESSAGE));
    }

    public function testNonAdminCannotAccessAdminMessagingInterface(): void
    {
        $client = static::createClient();
        $user = $this->createApplicantWithProfile('not_admin_'.bin2hex(random_bytes(4)).'@example.com');

        $client->loginUser($user);
        $client->request('GET', '/admin/users/messages/compose');

        self::assertResponseRedirects('/403');
    }

    public function testAdminCanModerateProfileFromUsersPage(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin_moderation_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_ADMIN']);
        $applicant = $this->createApplicantWithProfile('moderation_target_'.bin2hex(random_bytes(4)).'@example.com');
        $profile = $applicant->getDeveloperProfile();

        self::assertInstanceOf(DeveloperProfile::class, $profile);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->filter(sprintf('form[action="/admin/profiles/%d/moderate"]', $profile->getId()))->first()->form([
            'reason' => 'Merci de revoir ce profil avant republication.',
        ]));

        self::assertResponseRedirects('/admin/users');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedProfile = $entityManager->getRepository(DeveloperProfile::class)->find($profile->getId());
        self::assertInstanceOf(DeveloperProfile::class, $reloadedProfile);
        self::assertFalse($reloadedProfile->isPublic());
        self::assertSame('Merci de revoir ce profil avant republication.', $reloadedProfile->getModerationReason());

        $notification = $this->findNotificationFor($applicant, NotificationType::PROFILE_MODERATED);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertStringContainsString('revoir ce profil', (string) $notification->getContent());
    }

    private function createApplicantWithProfile(string $email): User
    {
        $user = $this->createUser($email, ['ROLE_APPLICANT']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Alice');
        $profile->setLastName('Martin');
        $profile->setHeadline('Developpeuse Symfony');
        $profile->setSlug('profile-'.bin2hex(random_bytes(5)));
        $profile->setBio('Profil applicant de test.');
        $profile->setCity('Paris');
        $profile->setCountry('France');
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
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

    /**
     * @return Notification[]
     */
    private function findNotificationsForType(NotificationType $type): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Notification::class)
            ->findBy(['type' => $type]);
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
