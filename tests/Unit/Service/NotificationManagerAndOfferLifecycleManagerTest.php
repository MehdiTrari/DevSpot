<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Company;
use App\Entity\JobOffer;
use App\Entity\Notification;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OfferStatus;
use App\Repository\ConversationRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\JobOfferRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManager;
use App\Service\OfferLifecycleManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationManagerAndOfferLifecycleManagerTest extends TestCase
{
    public function testNotifyApplicantOfferExpiredCreatesNotificationForApplicant(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $notificationRepository = $this->createMock(NotificationRepository::class);
        $favoriteProfileRepository = $this->createStub(FavoriteProfileRepository::class);
        $userRepository = $this->createStub(UserRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $manager = new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );

        $user = (new User())
            ->setEmail('applicant@example.com')
            ->setRoles(['ROLE_APPLICANT']);

        $company = (new Company())->setName('DevSpot');
        $recruiterUser = (new User())->setEmail('recruiter@example.com');
        $recruiterProfile = (new RecruiterProfile())
            ->setFirstName('Nora')
            ->setLastName('Recruiter')
            ->setJobTitle('CTO')
            ->setUser($recruiterUser)
            ->setCompany($company);

        $offer = (new JobOffer())
            ->setTitle('Développeur Symfony')
            ->setDescription('Offre backend Symfony')
            ->setRecruiterProfile($recruiterProfile);

        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('app_applicant_messages')
            ->willReturn('/applicant/messages');

        $notificationRepository
            ->expects(self::once())
            ->method('existsForUserTypeAndLink')
            ->with($user, NotificationType::JOB_OFFER_EXPIRED, '/applicant/messages?offer=0')
            ->willReturn(false);

        $persistedNotification = null;
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $notification) use (&$persistedNotification, $user): bool {
                self::assertInstanceOf(Notification::class, $notification);
                $persistedNotification = $notification;
                self::assertSame($user, $notification->getUser());
                self::assertSame(NotificationType::JOB_OFFER_EXPIRED, $notification->getType());
                self::assertSame('Une offre en cours de discussion a expiré', $notification->getTitle());
                self::assertSame('/applicant/messages?offer=0', $notification->getLink());
                self::assertFalse((bool) $notification->isRead());

                return true;
            }));

        self::assertTrue($manager->notifyApplicantOfferExpired($user, $offer));
        self::assertInstanceOf(Notification::class, $persistedNotification);
        self::assertStringContainsString('Développeur Symfony', (string) $persistedNotification->getContent());
        self::assertStringContainsString('chez DevSpot', (string) $persistedNotification->getContent());
    }

    public function testNotifyApplicantOfferExpiredReturnsFalseForNonApplicant(): void
    {
        $manager = $this->createNotificationManager(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(NotificationRepository::class),
            $this->createStub(FavoriteProfileRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(UrlGeneratorInterface::class),
        );

        $user = (new User())
            ->setEmail('recruiter@example.com')
            ->setRoles(['ROLE_RECRUITER']);

        $offer = (new JobOffer())
            ->setTitle('Développeur Front')
            ->setDescription('Offre front');

        self::assertFalse($manager->notifyApplicantOfferExpired($user, $offer));
    }

    public function testNotifyRecruiterIncompleteProfileReminderCreatesNotification(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $notificationRepository = $this->createMock(NotificationRepository::class);
        $favoriteProfileRepository = $this->createStub(FavoriteProfileRepository::class);
        $userRepository = $this->createStub(UserRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $manager = new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );

        $user = (new User())
            ->setEmail('recruiter@example.com')
            ->setRoles(['ROLE_RECRUITER']);
        $profile = (new RecruiterProfile())
            ->setFirstName('Nora')
            ->setLastName('Recruiter')
            ->setJobTitle('')
            ->setWorkEmail(null)
            ->setUser($user);
        $user->setRecruiterProfile($profile);

        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('app_recruiter_home')
            ->willReturn('/recruiter');

        $notificationRepository
            ->expects(self::once())
            ->method('existsUnreadForUserTypeAndLink')
            ->with($user, NotificationType::PROFILE_INCOMPLETE, '/recruiter')
            ->willReturn(false);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $notification) use ($user): bool {
                self::assertInstanceOf(Notification::class, $notification);
                self::assertSame($user, $notification->getUser());
                self::assertSame(NotificationType::PROFILE_INCOMPLETE, $notification->getType());
                self::assertStringContainsString('votre poste', (string) $notification->getContent());
                self::assertStringContainsString('votre email professionnel', (string) $notification->getContent());
                self::assertStringContainsString('votre entreprise', (string) $notification->getContent());

                return true;
            }));

        self::assertTrue($manager->notifyRecruiterIncompleteProfileReminder($user));
    }

    public function testExpireDueOffersClosesOffersNotifiesApplicantsAndFlushes(): void
    {
        $jobOfferRepository = $this->createMock(JobOfferRepository::class);
        $conversationRepository = $this->createMock(ConversationRepository::class);
        $notificationRepository = $this->createMock(NotificationRepository::class);
        $favoriteProfileRepository = $this->createStub(FavoriteProfileRepository::class);
        $userRepository = $this->createStub(UserRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $notificationManager = new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );
        $offerLifecycleManager = new OfferLifecycleManager(
            $jobOfferRepository,
            $conversationRepository,
            $notificationManager,
            $entityManager,
        );

        $applicant = (new User())
            ->setEmail('applicant@example.com')
            ->setRoles(['ROLE_APPLICANT']);
        $recruiterUser = (new User())->setEmail('recruiter@example.com');
        $recruiterProfile = (new RecruiterProfile())
            ->setFirstName('Nora')
            ->setLastName('Recruiter')
            ->setJobTitle('Lead recruiter')
            ->setUser($recruiterUser);
        $offer = (new JobOffer())
            ->setTitle('Développeur PHP')
            ->setDescription('Mission longue')
            ->setRecruiterProfile($recruiterProfile);

        $jobOfferRepository
            ->expects(self::once())
            ->method('findPublishedExpiredOffers')
            ->willReturn([$offer]);

        $conversationRepository
            ->expects(self::once())
            ->method('findDistinctApplicantsForRecruiter')
            ->with($recruiterUser)
            ->willReturn([$applicant]);

        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('app_applicant_messages')
            ->willReturn('/applicant/messages');

        $notificationRepository
            ->expects(self::once())
            ->method('existsForUserTypeAndLink')
            ->with($applicant, NotificationType::JOB_OFFER_EXPIRED, '/applicant/messages?offer=0')
            ->willReturn(false);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        self::assertSame(1, $offerLifecycleManager->expireDueOffers());
        self::assertSame(OfferStatus::CLOSED, $offer->getStatus());
        self::assertFalse((bool) $offer->isActive());
    }

    private function createNotificationManager(
        EntityManagerInterface $entityManager,
        NotificationRepository $notificationRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        UserRepository $userRepository,
        UrlGeneratorInterface $urlGenerator,
    ): NotificationManager {
        return new NotificationManager(
            $entityManager,
            $notificationRepository,
            $favoriteProfileRepository,
            $userRepository,
            $urlGenerator,
        );
    }
}
