<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PortfolioGenerationGuardE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testApplicantCannotGeneratePortfolioWithIncompleteProfile(): void
    {
        $entityManager = $this->resetDatabase();

        $email = sprintf('guard_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicant($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/applicant/profile/create');
        $this->completeStep1($client);
        $client->waitFor('#profile-step2-form');

        $this->visit($client, '/applicant/portfolio/generate');

        self::assertStringContainsString('/applicant', $client->getWebDriver()->getCurrentURL());
        self::assertSelectorTextContains('[data-notification-stack="true"]', 'Complète les 4 étapes avant de générer ton portfolio.');
        self::assertSelectorNotExists('a[href="/applicant/profile/step-4"]');
    }
}