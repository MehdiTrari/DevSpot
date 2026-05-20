<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ProfileCreationFlowTest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testApplicantCanCompleteProfileWizardAndReachPublicPortfolio(): void
    {
        $entityManager = $this->resetDatabase();
        $catalog = $this->createReferenceCatalog($entityManager);

        $email = sprintf('wizard_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicant($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/applicant');
        $client->clickLink('Créer mon profil');
        $client->waitFor('#developer_profile_firstName');

        $this->completeStep1($client);

        $client->waitFor('#profile-step2-form');

        $this->completeStep2($client, $catalog);

        $client->waitFor('#profile-step3-form');
        $client->submitForm('Étape suivante', [
            'developer_profile[githubUrl]' => 'https://github.com/mylene-martin',
            'developer_profile[linkedinUrl]' => 'https://www.linkedin.com/in/mylene-martin',
            'developer_profile[portfolioUrl]' => 'https://mylene.dev',
        ]);

        $client->waitFor('#profile-step4-form');
        $client->submitForm('Terminer', [
            'developer_profile[desiredPositions]' => [(string) $catalog['position']->getId()],
        ]);

        $client->waitFor('a[href="/applicant/portfolio/generate"]');
        self::assertSelectorExists('a[href="/applicant/portfolio/generate"]');

        $client->clickLink('Générer mon portfolio');
        $client->waitFor('#profil');

        self::assertPageTitleContains('Mylène Martin');
        self::assertStringContainsString('/profil/', $client->getWebDriver()->getCurrentURL());
        self::assertSelectorExists('#profil');
    }
}
