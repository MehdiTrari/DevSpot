<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Company;
use App\Entity\DeveloperProfile;
use App\Entity\Notification;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminNotificationRequestsTest extends WebTestCase
{
    public function testApplicantCanRequestSlugChangeAndAdminsReceiveNotification(): void
    {
        $client = static::createClient();
        $applicant = $this->createApplicantWithProfile();
        $admin = $this->createAdmin();

        $client->loginUser($applicant);
        $crawler = $client->request('GET', '/applicant');
        $client->submit($crawler->selectButton('Envoyer la demande')->form([
            'desired_slug' => 'nouveau-slug-admin-test',
        ]));

        self::assertResponseRedirects('/applicant');

        $notification = $this->findNotificationFor($admin, NotificationType::SLUG_CHANGE_REQUEST);
        self::assertNotNull($notification);
        self::assertStringContainsString('nouveau-slug-admin-test', (string) $notification->getContent());
    }

    public function testRecruiterCanRequestRoleChangeAndAdminsReceiveNotification(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiter();
        $admin = $this->createAdmin();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/recruiter');
        $client->submit($crawler->selectButton('Demander le rôle candidat')->form());

        self::assertResponseRedirects('/recruiter');

        $notification = $this->findNotificationFor($admin, NotificationType::ROLE_REQUEST);
        self::assertNotNull($notification);
        self::assertStringContainsString('passage de Recruteur vers Applicant', (string) $notification->getContent());
    }

    public function testAuthenticatedUserCanReportProfileAndAdminsReceiveNotification(): void
    {
        $client = static::createClient();
        $owner = $this->createApplicantWithProfile();
        $reporter = $this->createRecruiter();
        $admin = $this->createAdmin();

        $profile = $owner->getDeveloperProfile();
        self::assertNotNull($profile);

        $client->loginUser($reporter);
        $crawler = $client->request('GET', '/profil/' . $profile->getSlug());
        $client->submit($crawler->selectButton('Envoyer le signalement')->form([
            'category' => 'abusive_content',
            'reason' => 'Le contenu affiché semble non conforme et mérite une revue.',
        ]));

        self::assertResponseRedirects('/profil/' . $profile->getSlug());

        $notification = $this->findNotificationFor($admin, NotificationType::CONTENT_REPORTED);
        self::assertNotNull($notification);
        self::assertStringContainsString('signalé le profil', (string) $notification->getContent());
    }

    public function testRecruiterRegistrationCreatesCompanyNotificationForAdmins(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin();
        $crawler = $client->request('GET', '/register');

        $client->submit($crawler->filter('button[type="submit"]')->form([
            'registration_form[email]' => sprintf('company_notif_%s@example.com', bin2hex(random_bytes(8))),
            'registration_form[plainPassword]' => 'Password123!',
            'registration_form[agreeTerms]' => 1,
            'registration_form[accountType]' => 'recruiter',
            'registration_form[firstName]' => 'Nora',
            'registration_form[lastName]' => 'RH',
            'registration_form[companyName]' => 'Company Notify',
            'registration_form[workEmail]' => 'nora@company-notify.test',
        ]));

        self::assertResponseRedirects('/login');

        $notification = $this->findNotificationFor($admin, NotificationType::SYSTEM_NOTIFICATION, 'Nouvelle entreprise créée');
        self::assertNotNull($notification);
        self::assertStringContainsString('Company Notify', (string) $notification->getContent());
    }

    private function createAdmin(): User
    {
        return $this->createUser(sprintf('admin_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_ADMIN']);
    }

    private function createApplicantWithProfile(): User
    {
        $user = $this->createUser(sprintf('applicant_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_APPLICANT']);

        $profile = new DeveloperProfile();
        $profile->setFirstName('Alice');
        $profile->setLastName('Martin');
        $profile->setHeadline('Développeuse Symfony');
        $profile->setSlug('alice-' . bin2hex(random_bytes(4)));
        $profile->setBio('Profil de test pour les notifications admin.');
        $profile->setCity('Lyon');
        $profile->setCountry('France');
        $profile->setIsPublic(true);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $user;
    }

    private function createRecruiter(): User
    {
        $user = $this->createUser(sprintf('recruiter_%s@example.com', bin2hex(random_bytes(6))), ['ROLE_RECRUITER']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $company = new Company();
        $company->setName('Recruiter Test ' . bin2hex(random_bytes(3)));
        $entityManager->persist($company);

        $profile = new RecruiterProfile();
        $profile->setFirstName('Nora');
        $profile->setLastName('Recruiter');
        $profile->setJobTitle('Talent Acquisition');
        $profile->setWorkEmail($user->getEmail());
        $profile->setCompany($company);
        $profile->setUser($user);

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

    private function findNotificationFor(User $user, NotificationType $type, ?string $title = null): ?Notification
    {
        $criteria = [
            'user' => $user,
            'type' => $type,
        ];

        if (null !== $title) {
            $criteria['title'] = $title;
        }

        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Notification::class)
            ->findOneBy($criteria, ['id' => 'DESC']);
    }

    private function ensureSchemaExists(EntityManagerInterface $entityManager): void
    {
        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if ($schemaManager->tablesExist(['user'])) {
            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune métadonnée Doctrine disponible pour créer le schéma de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($metadata);
    }
}
