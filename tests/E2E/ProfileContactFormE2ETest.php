<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ProfileContactFormE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testRecruiterCanOpenAndSubmitDeveloperContactForm(): void
    {
        $entityManager = $this->resetDatabase();
        $profile = $this->createPublicProfile($entityManager);

        $email = sprintf('recruiter_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $this->createRecruiter($entityManager, $email, $password);
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);

        $this->visit($client, '/profil/'.$profile->getSlug());
        $client->waitFor('[data-contact-toggle]');

        self::assertSelectorIsNotVisible('textarea[name="contact_message[message]"]');

        $this->clickByJs($client, '[data-contact-toggle]');
        $client->waitForVisibility('textarea[name="contact_message[message]"]');

        $client->submitForm('Envoyer le message', [
            'contact_message[recruiterName]' => 'Mylène Recruiter',
            'contact_message[recruiterEmail]' => $email,
            'contact_message[subject]' => 'Opportunité Symfony',
            'contact_message[message]' => 'Bonjour, nous avons une opportunité CDI Symfony pour vous.',
        ]);

        $client->waitFor('body');
        self::assertSelectorTextContains('body', 'Votre message a bien été envoyé au développeur.');
    }
}
