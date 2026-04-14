<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\FavoriteProfile;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecruiterFavoritesTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    public function testRecruiterCanAddFavoriteFromHomeDirectory(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true, 'Alice', 'Martin');
        $recruiter = $this->createRecruiterUser(sprintf('recruiter_home_%s@example.com', bin2hex(random_bytes(6))), 'password123');

        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/');
        $form = $crawler->filter(sprintf('form[action="/recruiter/favorites/%d/add"]', $profile->getId()))->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Profil ajouté aux favoris.');

        $favorite = $this->findFavorite($recruiter, $profile);
        self::assertNotNull($favorite);

        $client->request('GET', '/recruiter');
        self::assertSelectorTextContains('body', 'Alice Martin');
    }

    public function testRecruiterCanAddFavoriteFromPublicProfilePage(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true, 'Camille', 'Durand');
        $recruiter = $this->createRecruiterUser(sprintf('recruiter_profile_%s@example.com', bin2hex(random_bytes(6))), 'password123');

        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/profil/' . $profile->getSlug());
        $form = $crawler->filter(sprintf('form[action="/recruiter/favorites/%d/add"]', $profile->getId()))->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/profil/' . $profile->getSlug());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'Profil ajouté aux favoris.');
        self::assertGreaterThan(
            0,
            $crawler->filter(sprintf('form[action="/recruiter/favorites/%d/remove"]', $profile->getId()))->count()
        );

        $favorite = $this->findFavorite($recruiter, $profile);
        self::assertNotNull($favorite);
    }

    public function testRecruiterCannotAddSameFavoriteTwice(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true, 'Nina', 'Petit');
        $recruiter = $this->createRecruiterUser(sprintf('recruiter_duplicate_%s@example.com', bin2hex(random_bytes(6))), 'password123');

        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/profil/' . $profile->getSlug());
        $form = $crawler->filter(sprintf('form[action="/recruiter/favorites/%d/add"]', $profile->getId()))->first()->form();
        $token = $form->get('_token')->getValue();

        $client->submit($form);
        self::assertResponseRedirects('/profil/' . $profile->getSlug());
        $client->followRedirect();

        $client->request('POST', '/recruiter/favorites/' . $profile->getId() . '/add', [
            '_token' => $token,
            '_redirect' => '/profil/' . $profile->getSlug(),
        ]);

        self::assertResponseRedirects('/profil/' . $profile->getSlug());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Ce profil est déjà dans vos favoris.');
        self::assertCount(1, $this->findFavoritesForRecruiter($recruiter));
    }

    public function testRecruiterDashboardShowsOnlyOwnFavoritesAndAllowsRemoval(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true, 'Unique', 'Favorite');
        $recruiterA = $this->createRecruiterUser(sprintf('recruiter_a_%s@example.com', bin2hex(random_bytes(6))), 'password123');
        $recruiterB = $this->createRecruiterUser(sprintf('recruiter_b_%s@example.com', bin2hex(random_bytes(6))), 'password123');
        $this->createFavorite($recruiterA, $profile);

        $client->loginUser($recruiterB);
        $client->request('GET', '/recruiter');
        self::assertSelectorTextNotContains('body', 'Unique Favorite');

        $client->loginUser($recruiterA);
        $crawler = $client->request('GET', '/recruiter');
        self::assertSelectorTextContains('body', 'Unique Favorite');

        $form = $crawler->filter(sprintf('form[action="/recruiter/favorites/%d/remove"]', $profile->getId()))->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/recruiter');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Profil retiré des favoris.');
        self::assertSelectorTextNotContains('body', 'Unique Favorite');
        self::assertCount(0, $this->findFavoritesForRecruiter($recruiterA));
    }

    public function testRecruiterCanAccessDedicatedFavoritesPage(): void
    {
        $client = static::createClient();
        $profile = $this->createProfileOwnerWithPortfolio(true, 'Laura', 'Favre');
        $recruiter = $this->createRecruiterUser(sprintf('recruiter_page_%s@example.com', bin2hex(random_bytes(6))), 'password123');
        $this->createFavorite($recruiter, $profile);

        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/recruiter');
        self::assertGreaterThan(0, $crawler->filter('a[href="/recruiter/favorites"]')->count());

        $client->request('GET', '/recruiter/favorites');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tes profils favoris.');
        self::assertSelectorTextContains('body', 'Laura Favre');
    }

    private function createProfileOwnerWithPortfolio(bool $isPublic, string $firstName, string $lastName): DeveloperProfile
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($entityManager);

        $user = new User();
        $user->setEmail(sprintf('applicant_%s@example.com', bin2hex(random_bytes(6))));
        $user->setRoles(['ROLE_APPLICANT']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $profile = new DeveloperProfile();
        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setHeadline('Développeur Symfony');
        $profile->setSlug(strtolower($firstName . '-' . $lastName . '-' . bin2hex(random_bytes(4))));
        $profile->setIsPublic($isPublic);
        $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
        $profile->setUser($user);
        $user->setDeveloperProfile($profile);

        $entityManager->persist($user);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
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

        $recruiterProfile = new RecruiterProfile();
        $recruiterProfile->setFirstName('Recruiter');
        $recruiterProfile->setLastName('Test');
        $recruiterProfile->setJobTitle('');
        $recruiterProfile->setWorkEmail($email);
        $recruiterProfile->setUser($user);
        $user->setRecruiterProfile($recruiterProfile);

        $entityManager->persist($user);
        $entityManager->persist($recruiterProfile);
        $entityManager->flush();

        return $user;
    }

    private function createFavorite(User $recruiter, DeveloperProfile $profile): FavoriteProfile
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $favorite = new FavoriteProfile();
        $favorite->setRecruiterProfile($recruiter->getRecruiterProfile());
        $favorite->setDeveloperProfile($profile);

        $entityManager->persist($favorite);
        $entityManager->flush();

        return $favorite;
    }

    private function findFavorite(User $recruiter, DeveloperProfile $profile): ?FavoriteProfile
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(FavoriteProfile::class)->findOneBy([
            'recruiterProfile' => $recruiter->getRecruiterProfile(),
            'developerProfile' => $profile,
        ]);
    }

    /**
     * @return list<FavoriteProfile>
     */
    private function findFavoritesForRecruiter(User $recruiter): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(FavoriteProfile::class)->findBy([
            'recruiterProfile' => $recruiter->getRecruiterProfile(),
        ]);
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
