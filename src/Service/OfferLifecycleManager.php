<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\OfferStatus;
use App\Repository\ConversationRepository;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OfferLifecycleManager
{
    public function __construct(
        private readonly JobOfferRepository $jobOfferRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly NotificationManager $notificationManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function expireDueOffers(): int
    {
        $expiredOffers = $this->jobOfferRepository->findPublishedExpiredOffers(new \DateTimeImmutable('today'));
        if ([] === $expiredOffers) {
            return 0;
        }

        $processed = 0;

        foreach ($expiredOffers as $offer) {
            $offer->setStatus(OfferStatus::CLOSED);
            $recruiterUser = $offer->getRecruiterProfile()?->getUser();

            if ($recruiterUser instanceof User) {
                foreach ($this->conversationRepository->findDistinctApplicantsForRecruiter($recruiterUser) as $applicant) {
                    if ($applicant instanceof User) {
                        $this->notificationManager->notifyApplicantOfferExpired($applicant, $offer);
                    }
                }
            }

            ++$processed;
        }

        if ($processed > 0) {
            $this->entityManager->flush();
        }

        return $processed;
    }
}