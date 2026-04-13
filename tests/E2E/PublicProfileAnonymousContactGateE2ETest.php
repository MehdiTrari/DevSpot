<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PublicProfileAnonymousContactGateE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testAnonymousVisitorMustLogInToContactDeveloper(): void
    {
        $entityManager = $this->resetDatabase();
        $profile = $this->createPublicProfile($entityManager);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();

        $this->visit($client, '/profil/'.$profile->getSlug());

        self::assertSelectorExists('a[href="/login"]');
        self::assertStringContainsString('Se connecter pour contacter', $client->getPageSource());
        self::assertStringContainsString('Vous devez vous connecter pour entrer en contact avec ce développeur.', $client->getPageSource());
        self::assertSelectorNotExists('[data-contact-toggle]');
        self::assertSelectorNotExists('textarea[name="contact_message[message]"]');
    }
}