<?php

namespace App\Service;

use App\Entity\SupportRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SupportRequestManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationManager $notificationManager,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function submit(SupportRequest $supportRequest): bool
    {
        $this->entityManager->persist($supportRequest);
        $this->notificationManager->notifyAdminsSupportRequest($supportRequest);
        $this->entityManager->flush();

        $admins = array_values(array_filter(
            $this->userRepository->findAdmins(),
            static fn (User $admin): bool => null !== $admin->getEmail() && '' !== trim((string) $admin->getEmail())
        ));

        usort($admins, static function (User $left, User $right): int {
            return strcmp((string) $left->getEmail(), (string) $right->getEmail());
        });

        if ([] === $admins) {
            return true;
        }

        $adminUrl = $this->urlGenerator->generate('app_admin_support_requests', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $allEmailsSent = true;

        foreach ($admins as $admin) {
            try {
                $this->mailer->send(
                    (new TemplatedEmail())
                        ->from(new Address('no-reply@devspot.software', 'DevSpot Support Bot'))
                        ->to((string) $admin->getEmail())
                        ->subject(sprintf('Nouvelle demande de support : %s', (string) ($supportRequest->getSubject() ?? 'Sans objet')))
                        ->htmlTemplate('emails/support_request_admin.html.twig')
                        ->context([
                            'admin' => $admin,
                            'requesterDisplayName' => $supportRequest->getRequesterDisplayName(),
                            'requesterEmail' => $supportRequest->getRequesterEmail(),
                            'supportSubject' => $supportRequest->getSubject(),
                            'supportMessage' => $supportRequest->getMessage(),
                            'submittedAt' => $supportRequest->getCreatedAt(),
                            'adminUrl' => $adminUrl,
                        ])
                );
            } catch (\Throwable $exception) {
                $allEmailsSent = false;
                $this->logger->error('Envoi email support admin echoue.', [
                    'supportRequestId' => $supportRequest->getId(),
                    'adminUserId' => $admin->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allEmailsSent;
    }

    public function resolveRequesterDisplayName(User $user): string
    {
        $recruiterProfile = $user->getRecruiterProfile();
        if (null !== $recruiterProfile) {
            $fullName = trim(sprintf('%s %s', (string) $recruiterProfile->getFirstName(), (string) $recruiterProfile->getLastName()));
            if ('' !== $fullName) {
                return $fullName;
            }
        }

        $developerProfile = $user->getDeveloperProfile();
        if (null !== $developerProfile) {
            $fullName = trim(sprintf('%s %s', (string) $developerProfile->getFirstName(), (string) $developerProfile->getLastName()));
            if ('' !== $fullName) {
                return $fullName;
            }
        }

        return $user->getEmail() ?? 'Utilisateur DevSpot';
    }
}
