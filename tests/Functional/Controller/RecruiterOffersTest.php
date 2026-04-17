<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\JobOffer;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\OfferStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecruiterOffersTest extends WebTestCase
{
    private static bool $schemaInitialized = false;

    // ── Create offer ──

    public function testCreateOfferPageIsAccessible(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        $client->request('GET', '/recruiter/offers/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Créer une nouvelle offre');
    }

    public function testCreateOfferRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/recruiter/offers/new');

        self::assertResponseRedirects();
    }

    public function testCreateOfferSubmitsSuccessfully(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/recruiter/offers/new');
        $form = $crawler->selectButton('Créer l\'offre')->form([
            'job_offer[title]' => 'Développeur Symfony Senior',
            'job_offer[description]' => 'Nous recherchons un développeur Symfony expérimenté.',
            'job_offer[location]' => 'Paris, France',
            'job_offer[applicationDeadline]' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'job_offer[status]' => 'published',
        ]);

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $offer = $em->getRepository(JobOffer::class)->findOneBy(['title' => 'Développeur Symfony Senior']);

        self::assertNotNull($offer);
        self::assertSame('Développeur Symfony Senior', $offer->getTitle());
        self::assertSame(OfferStatus::PUBLISHED, $offer->getStatus());
        self::assertTrue($offer->isActive());
        self::assertSame((new \DateTimeImmutable('+10 days'))->format('Y-m-d'), $offer->getApplicationDeadline()?->format('Y-m-d'));
        self::assertSame($recruiter->getRecruiterProfile()->getId(), $offer->getRecruiterProfile()->getId());
    }

    public function testCreateOfferAsDraft(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/recruiter/offers/new');
        $form = $crawler->selectButton('Créer l\'offre')->form([
            'job_offer[title]' => 'Offre brouillon test',
            'job_offer[description]' => 'Description du brouillon.',
            'job_offer[applicationDeadline]' => (new \DateTimeImmutable('+15 days'))->format('Y-m-d'),
            'job_offer[status]' => 'draft',
        ]);

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $offer = $em->getRepository(JobOffer::class)->findOneBy(['title' => 'Offre brouillon test']);

        self::assertNotNull($offer);
        self::assertSame(OfferStatus::DRAFT, $offer->getStatus());
        self::assertFalse($offer->isActive());
    }

    public function testCreateOfferValidationRequiresTitleAndDescription(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        $crawler = $client->request('GET', '/recruiter/offers/new');
        $form = $crawler->selectButton('Créer l\'offre')->form([
            'job_offer[title]' => '',
            'job_offer[description]' => '',
            'job_offer[applicationDeadline]' => '',
        ]);

        $client->submit($form);
        // Should stay on the same page (no redirect = validation errors)
        self::assertResponseIsUnprocessable();
    }

    // ── Toggle status ──

    public function testToggleStatusCloseOffer(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create a published offer via the form
        $this->submitCreateOfferForm($client, 'Offre à fermer', 'published');

        // Go to offers page — find the close button form
        $crawler = $client->request('GET', '/recruiter/offers');
        $closeForms = $crawler->filter('form input[name="action"][value="close"]');
        self::assertGreaterThan(0, $closeForms->count(), 'A close form should exist for published offers.');

        $form = $closeForms->first()->ancestors()->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/recruiter/offers');

        // Verify status changed
        $crawler = $client->request('GET', '/recruiter/offers');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Fermée', $body);
    }

    public function testToggleStatusReopenOffer(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create published, then close it
        $this->submitCreateOfferForm($client, 'Offre à rouvrir', 'published');
        $crawler = $client->request('GET', '/recruiter/offers');
        $form = $crawler->filter('form input[name="action"][value="close"]')->first()->ancestors()->first()->form();
        $client->submit($form);
        $client->followRedirect();

        // Now reopen it
        $crawler = $client->request('GET', '/recruiter/offers');
        $reopenForms = $crawler->filter('form input[name="action"][value="publish"]');
        self::assertGreaterThan(0, $reopenForms->count());

        $form = $reopenForms->first()->ancestors()->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/recruiter/offers');

        $crawler = $client->request('GET', '/recruiter/offers');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Publiée', $body);
    }

    public function testToggleStatusPublishDraft(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create a draft
        $this->submitCreateOfferForm($client, 'Brouillon à publier', 'draft');

        $crawler = $client->request('GET', '/recruiter/offers');
        $publishForms = $crawler->filter('form input[name="action"][value="publish"]');
        self::assertGreaterThan(0, $publishForms->count());

        $form = $publishForms->first()->ancestors()->first()->form();
        $client->submit($form);

        self::assertResponseRedirects('/recruiter/offers');

        $crawler = $client->request('GET', '/recruiter/offers');
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Publiée', $body);
    }

    public function testToggleStatusRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create a published offer
        $this->submitCreateOfferForm($client, 'Offre token invalide', 'published');

        // Get the offer ID from the page
        $crawler = $client->request('GET', '/recruiter/offers');
        $toggleForm = $crawler->filter('form input[name="action"][value="close"]')->first()->ancestors()->first();
        $action = $toggleForm->attr('action');

        // Submit with an invalid token
        $client->request('POST', $action, [
            '_token' => 'invalid-token',
            'action' => 'close',
        ]);

        self::assertResponseRedirects('/recruiter/offers');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Token CSRF invalide.');
    }

    public function testToggleStatusDeniedForOtherRecruiter(): void
    {
        $client = static::createClient();
        $ownerRecruiter = $this->createRecruiterUser();
        $otherRecruiter = $this->createRecruiterUser();

        // Owner creates an offer
        $client->loginUser($ownerRecruiter);
        $this->submitCreateOfferForm($client, 'Offre protégée', 'published');

        // Get the toggle URL
        $crawler = $client->request('GET', '/recruiter/offers');
        $toggleForm = $crawler->filter('form input[name="action"][value="close"]')->first()->ancestors()->first();
        $action = $toggleForm->attr('action');
        $token = $toggleForm->filter('input[name="_token"]')->attr('value');

        // Login as other recruiter and try
        $client->loginUser($otherRecruiter);
        $client->request('POST', $action, [
            '_token' => $token,
            'action' => 'close',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Offers list page ──

    public function testOffersPageIsAccessible(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        $client->request('GET', '/recruiter/offers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes offres');
    }

    public function testOffersPageShowsStatusBadges(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create offers via the form to ensure proper association
        $this->submitCreateOfferForm($client, 'Offre publiée badge', 'published');
        $this->submitCreateOfferForm($client, 'Offre brouillon badge', 'draft');

        $crawler = $client->request('GET', '/recruiter/offers');
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Offre publiée badge', $body);
        self::assertStringContainsString('Offre brouillon badge', $body);
        self::assertStringContainsString('Publiée', $body);
        self::assertStringContainsString('Brouillon', $body);
    }

    // ── Dashboard stats ──

    public function testDashboardShowsOfferStats(): void
    {
        $client = static::createClient();
        $recruiter = $this->createRecruiterUser();
        $client->loginUser($recruiter);

        // Create offers via forms
        $this->submitCreateOfferForm($client, 'Stat publiée', 'published');
        $this->submitCreateOfferForm($client, 'Stat brouillon', 'draft');

        $crawler = $client->request('GET', '/recruiter');
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('offre publiée', $body);
        self::assertStringContainsString('brouillon', $body);
        self::assertStringContainsString('au total', $body);
    }

    // ── Helpers ──

    private function submitCreateOfferForm($client, string $title, string $status = 'published'): void
    {
        $crawler = $client->request('GET', '/recruiter/offers/new');
        $form = $crawler->selectButton('Créer l\'offre')->form([
            'job_offer[title]' => $title,
            'job_offer[description]' => 'Description de test pour ' . $title,
            'job_offer[applicationDeadline]' => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'),
            'job_offer[status]' => $status,
        ]);
        $client->submit($form);
        $client->followRedirect();
    }

    private function createRecruiterUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->ensureSchemaExists($em);

        $email = sprintf('recruiter_%s@example.com', bin2hex(random_bytes(6)));

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_RECRUITER']);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $recruiterProfile = new RecruiterProfile();
        $recruiterProfile->setFirstName('Recruiter');
        $recruiterProfile->setLastName('Test');
        $recruiterProfile->setJobTitle('');
        $recruiterProfile->setWorkEmail($email);
        $recruiterProfile->setUser($user);
        $user->setRecruiterProfile($recruiterProfile);

        $em->persist($user);
        $em->persist($recruiterProfile);
        $em->flush();

        return $user;
    }

    private function ensureSchemaExists(EntityManagerInterface $em): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune métadonnée Doctrine disponible.');
        }

        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::$schemaInitialized = true;
    }
}
