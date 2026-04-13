<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ProfileVisibilityToggleE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testApplicantCanTogglePortfolioVisibilityFromDashboardAfterGeneration(): void
    {
        $entityManager = $this->resetDatabase();
        $catalog = $this->createReferenceCatalog($entityManager);

        $email = sprintf('visibility_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicant($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/applicant/profile/create');
        $this->completeStep1($client);
    $client->waitFor('#profile-step2-form');
        $this->completeStep2($client, $catalog);
    $client->waitFor('#profile-step3-form');
        $this->completeStep3($client);
    $client->waitFor('#profile-step4-form');
        $this->completeStep4($client, $catalog);

        $client->waitFor('[data-visibility-toggle="true"]');
    self::assertStringContainsString('Privé', $this->getTextContent($client, '[data-visibility-badge="true"]'));

        $this->click($client, '[data-visibility-button="true"]');
        $client->waitFor('[data-notification-stack="true"]');
    self::assertStringContainsString('Génère d\'abord ton portfolio avant de le rendre public.', $this->waitForTextContent($client, '[data-notification-stack="true"]', 'Génère d\'abord ton portfolio avant de le rendre public.'));
    self::assertStringContainsString('Privé', $this->getTextContent($client, '[data-visibility-badge="true"]'));

        $client->clickLink('Générer mon portfolio');
        $client->waitFor('#profil');

        $this->visit($client, '/applicant');
        $client->waitFor('[data-visibility-button="true"]');

        $this->click($client, '[data-visibility-button="true"]');
        $client->waitFor('[data-visibility-badge="true"]');
        self::assertStringContainsString('Public', $this->waitForTextContent($client, '[data-visibility-badge="true"]', 'Public'));
        self::assertStringContainsString('Passer en privé', $this->waitForTextContent($client, '[data-visibility-button="true"]', 'Passer en privé'));
        self::assertStringContainsString('Ton portfolio est maintenant public.', $this->waitForTextContent($client, '[data-notification-stack="true"]', 'Ton portfolio est maintenant public.'));

        $this->click($client, '[data-visibility-button="true"]');
        $client->waitFor('[data-visibility-badge="true"]');
        self::assertStringContainsString('Privé', $this->waitForTextContent($client, '[data-visibility-badge="true"]', 'Privé'));
        self::assertStringContainsString('Passer en public', $this->waitForTextContent($client, '[data-visibility-button="true"]', 'Passer en public'));
        self::assertStringContainsString('Ton portfolio est maintenant privé.', $this->waitForTextContent($client, '[data-notification-stack="true"]', 'Ton portfolio est maintenant privé.'));
    }
}