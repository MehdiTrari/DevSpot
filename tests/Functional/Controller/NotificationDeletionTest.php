<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationDeletionTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testUserCanDeleteNotificationFromNotificationsPage(): void
    {
        $client = static::createClient();
        $user = $this->createUser('notifications_'.bin2hex(random_bytes(6)).'@example.com');
        $notification = $this->createNotification($user, 'Nouveau message reçu', 'Vous avez reçu un nouveau message.', false);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        $deleteFormSelector = sprintf('form[action^="/notifications/%d/delete"]', $notification->getId());
        self::assertSelectorExists($deleteFormSelector);

        $deleteForm = $crawler->filter($deleteFormSelector)->form();
        $client->submit($deleteForm);

        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Aucune notification pour le moment.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        /** @var NotificationRepository $notificationRepository */
        $notificationRepository = static::getContainer()->get(NotificationRepository::class);
        self::assertCount(0, $notificationRepository->findForUserOrdered($user));
    }

    public function testNotificationsArePaginatedByTenPerPage(): void
    {
        $client = static::createClient();
        $user = $this->createUser('notifications_pagination_'.bin2hex(random_bytes(6)).'@example.com');

        for ($i = 1; $i <= 12; ++$i) {
            $this->createNotification(
                $user,
                sprintf('Notification %02d', $i),
                sprintf('Contenu %02d', $i),
                false
            );
        }

        $client->loginUser($user);

        $crawlerPageOne = $client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $crawlerPageOne->filter('article'));
        self::assertSelectorTextContains('body', 'Page 1 / 2');

        $crawlerPageTwo = $client->request('GET', '/notifications?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawlerPageTwo->filter('article'));
        self::assertSelectorTextContains('body', 'Page 2 / 2');
    }

    private function createUser(string $email): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->initializeSchemaIfNeeded();

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createNotification(User $user, string $title, string $content, bool $isRead): Notification
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->initializeSchemaIfNeeded();

        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType(NotificationType::SYSTEM_NOTIFICATION);
        $notification->setTitle($title);
        $notification->setContent($content);
        $notification->setIsRead($isRead);

        $entityManager->persist($notification);
        $entityManager->flush();

        return $notification;
    }

    private function initializeSchemaIfNeeded(): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
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
