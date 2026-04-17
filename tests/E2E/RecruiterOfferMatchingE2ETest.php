<?php

namespace App\Tests\E2E;

use App\Entity\FavoriteProfile;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RecruiterOfferMatchingE2ETest extends PantherWebTestCase
{
    #[RunInSeparateProcess]
    public function testRecruiterCanLaunchMatchingAndFavoriteAProfileFromOfferDetail(): void
    {
        $entityManager = $this->resetDatabase();

        $profile = $this->createPublicProfile($entityManager);
        $profile->setHeadline('Développeuse Symfony full-stack');
        $profile->setBio('Je développe des APIs Symfony et des interfaces produit modernes.');

        $email = sprintf('recruiter_%s@example.com', bin2hex(random_bytes(6)));
        $password = 'password123';
        $recruiter = $this->createRecruiter($entityManager, $email, $password);
        $offer = $this->createJobOffer($entityManager, $recruiter, [
            'title' => 'Développeur Symfony',
            'description' => 'Nous cherchons un développeur Symfony pour construire des APIs et des interfaces web.',
        ]);
        $entityManager->flush();
        $this->disconnectKernel($entityManager);

        $client = static::createPantherClient();
        $this->login($client, $email, $password);
        $this->visit($client, '/recruiter/offers/'.$offer->getId());

        $client->waitFor('#btn-launch-matching');
        $this->clickByJs($client, '#btn-launch-matching');

        $client->waitForVisibility('#matching-section');

        $summaryText = $this->waitForTextContent($client, '#summary-count', '1 profils', 20000);
        self::assertStringContainsString('1 profils', $summaryText);

        $resultsText = $this->waitForTextContent($client, '#matching-results', 'Nadia Front', 20000);
        self::assertStringContainsString('Nadia Front', $resultsText);

        $client->waitFor('[data-fav-dev]');
        $this->clickByJs($client, '[data-fav-dev]');

        $deadline = microtime(true) + 10;
        $isFavorite = false;

        while (microtime(true) < $deadline) {
            $favoriteState = $client->executeScript(<<<'JS'
                const button = document.querySelector('[data-fav-dev]');

                return button ? button.getAttribute('data-is-fav') : null;
            JS);

            if ('1' === (string) $favoriteState) {
                $isFavorite = true;
                break;
            }

            usleep(100000);
        }

        self::assertTrue($isFavorite, 'Le profil devrait être ajouté aux favoris depuis les résultats de matching.');

        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $favorites = $entityManager->getRepository(FavoriteProfile::class)->findAll();

        self::assertCount(1, $favorites);
    }
}
