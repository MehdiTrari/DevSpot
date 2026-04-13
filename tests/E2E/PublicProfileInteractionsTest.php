<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PublicProfileInteractionsTest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testAnonymousVisitorCanFilterPublicProfileSections(): void
    {
        $entityManager = $this->resetDatabase();
        $profile = $this->createPublicProfile($entityManager);
        $email = sprintf('viewer_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createRecruiter($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);
        $this->visit($client, '/profil/'.$profile->getSlug());

        self::assertPageTitleContains($profile->getFirstName().' '.$profile->getLastName());
        self::assertStringContainsString('/profil/'.$profile->getSlug(), $client->getWebDriver()->getCurrentURL());
        self::assertSelectorExists('#profil');
        self::assertSelectorExists('#experiences');
        self::assertSelectorExists('#liens');

        $client->waitFor('button[data-filter="experiences"]');
        $client->getCrawler()->filter('button[data-filter="experiences"]')->click();

        $client->waitForVisibility('#experiences');
        $client->waitForInvisibility('#profil');
        $client->waitForInvisibility('#liens');

        self::assertSelectorIsVisible('#experiences');
        self::assertSelectorIsNotVisible('#profil');
        self::assertSelectorIsNotVisible('#liens');

        $client->getCrawler()->filter('button[data-filter="liens"]')->click();

        $client->waitForVisibility('#liens');

        self::assertSelectorIsVisible('#experiences');
        self::assertSelectorIsVisible('#liens');
        self::assertSelectorIsNotVisible('#profil');
    }
}