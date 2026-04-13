<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PrivateProfileAccessE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testRecruiterCannotAccessAnotherUsersPrivateProfile(): void
    {
        $entityManager = $this->resetDatabase();
        $profile = $this->createPrivateGeneratedProfile($entityManager);
        $email = sprintf('outsider_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createRecruiter($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/profil/'.$profile->getSlug());

        self::assertStringContainsString('/403', $client->getWebDriver()->getCurrentURL());
        self::assertSelectorTextContains('h1', 'Accès refusé.');
    }
}