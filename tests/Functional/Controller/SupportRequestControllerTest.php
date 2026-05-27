<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\SupportRequest;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SupportRequestControllerTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testLoggedUserSeesSupportEntryAndHelpPageProvidesContactCta(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('support_view_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_APPLICANT']);

        $client->loginUser($user);
        $client->request('GET', '/');

        self::assertResponseRedirects('/applicant/dashboard');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/help"]');
        self::assertSelectorExists('a[href="/faq"]');

        $client->request('GET', '/help');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Utilise DevSpot en quelques');

        $client->request('GET', '/faq');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Questions fréquentes');
        self::assertSelectorExists('a[href="/settings/support"]');
    }

    public function testAuthenticatedUserCanSubmitSupportRequestAndAdminsReceiveNotificationsAndEmails(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('support_sender_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_APPLICANT']);
        $firstAdmin = $this->createUser(sprintf('support_admin_a_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_ADMIN']);
        $secondAdmin = $this->createUser(sprintf('support_admin_b_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_ADMIN']);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/support');

        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Envoyer la demande')->form([
            'support_request[subject]' => 'Probleme de notifications',
            'support_request[message]' => 'Bonjour, je ne retrouve plus mes alertes dans mon espace depuis ce matin.',
        ]));

        self::assertResponseRedirects('/settings/support');

        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Votre demande de support a bien été envoyée à l’équipe administratrice.');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $savedRequest = $entityManager->getRepository(SupportRequest::class)->findOneBy([
            'user' => $user,
        ], ['id' => 'DESC']);

        self::assertInstanceOf(SupportRequest::class, $savedRequest);
        self::assertSame('Probleme de notifications', $savedRequest->getSubject());
        self::assertSame('Bonjour, je ne retrouve plus mes alertes dans mon espace depuis ce matin.', $savedRequest->getMessage());
        self::assertSame($user->getEmail(), $savedRequest->getRequesterEmail());

        $firstNotification = $this->findNotificationFor($firstAdmin, NotificationType::SUPPORT_REQUEST);
        $secondNotification = $this->findNotificationFor($secondAdmin, NotificationType::SUPPORT_REQUEST);

        self::assertInstanceOf(Notification::class, $firstNotification);
        self::assertInstanceOf(Notification::class, $secondNotification);
        self::assertStringContainsString((string) $user->getEmail(), (string) $firstNotification->getContent());
        self::assertStringContainsString('Probleme de notifications', (string) $firstNotification->getContent());
    }

    public function testSupportFormRejectsEmptyRequiredFields(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('support_invalid_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_RECRUITER']);
        $beforeCount = static::getContainer()->get(EntityManagerInterface::class)->getRepository(SupportRequest::class)->count([]);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings/support');

        $client->submit($crawler->selectButton('Envoyer la demande')->form([
            'support_request[subject]' => '',
            'support_request[message]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Merci de renseigner un objet.');
        self::assertSelectorTextContains('body', 'Merci de renseigner un message.');
        self::assertSame($beforeCount, static::getContainer()->get(EntityManagerInterface::class)->getRepository(SupportRequest::class)->count([]));
    }

    public function testAnonymousUserCannotAccessSupportForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings/support');

        self::assertResponseRedirects('/403');
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
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['messenger_messages'])) {
            $entityManager->getConnection()->executeStatement('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
            $entityManager->getConnection()->executeStatement('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        }

        self::$schemaInitialized = true;
    }
}
