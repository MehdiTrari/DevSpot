<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ProfileStep3ValidationE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testApplicantSeesHelpfulValidationErrorForInvalidGithubUrl(): void
    {
        $entityManager = $this->resetDatabase();
        $catalog = $this->createReferenceCatalog($entityManager);

        $email = sprintf('step3_%s@example.com', bin2hex(random_bytes(6)));
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
        $this->setValue($client, '#developer_profile_githubUrl', 'http://github.com/mylene');
        $this->setValue($client, '#developer_profile_linkedinUrl', '');
        $this->setValue($client, '#developer_profile_portfolioUrl', '');
        $client->executeScript(<<<'JS'
            const form = document.getElementById('profile-step3-form');
            if (form) {
                form.requestSubmit();
            }
        JS);

        $client->waitFor('#profile-step3-form');
        self::assertStringContainsString('/applicant/profile/step-3', $client->getWebDriver()->getCurrentURL());

        self::assertMatchesRegularExpression(
            '/Le lien GitHub doit (?:être une URL HTTPS valide\.|commencer par https:\/\/github\.com\/)/u',
            $client->getPageSource()
        );
    }
}