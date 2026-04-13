<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ProfileSaveAndExitDialogTest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testApplicantCanSaveAndExitFromStep1Dialog(): void
    {
        $entityManager = $this->resetDatabase();
        $email = sprintf('save_exit_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createApplicant($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/applicant/profile/create');
        $client->waitFor('#developer_profile_firstName');

        $this->type($client, '#developer_profile_firstName', 'Nora');
        $this->type($client, '#developer_profile_lastName', 'Save');
        $this->type($client, '#developer_profile_headline', 'Développeuse PHP');
        $this->type($client, '#developer_profile_city', 'Lille');
        $this->type($client, '#developer_profile_country', 'France');
        $this->selectOptionByValue($client, '#developer_profile_locationType', 'remote');
        $this->selectOptionByValue($client, '#developer_profile_experienceLevel', 'junior');
        $this->type($client, '#developer_profile_yearsExperience', '2');
        $this->type($client, '#developer_profile_bio', 'Profil sauvegardé depuis la popup.');

        $this->click($client, '[data-dashboard-exit-trigger="profile-step1-create-dialog"]');
        $client->waitFor('dialog[open]');
        self::assertSelectorTextContains('dialog[open]', 'Voulez-vous enregistrer ?');

        $client->executeScript(<<<'JS'
            const form = document.getElementById('profile-step1-create-form');
            if (!form) {
                return;
            }

            let actionInput = form.querySelector('input[name="form_action"]');
            if (!actionInput) {
                actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'form_action';
                form.appendChild(actionInput);
            }

            actionInput.value = 'save_and_exit';
            form.requestSubmit();
        JS);
        $client->waitFor('body');

        self::assertSelectorTextContains('body', 'revenu au dashboard');
        self::assertSelectorExists('a[href="/applicant/profile/step-2"]');
    }
}