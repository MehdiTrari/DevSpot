<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RecruiterConversationStarter
{
    public function __construct(
        private readonly ConversationRepository $conversationRepository,
        private readonly NotificationManager $notificationManager,
        private readonly ChatMercure $chatMercure,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function startConversation(
        DeveloperProfile $profile,
        User $recruiterUser,
        string $recruiterName,
        string $recruiterEmail,
        string $subject,
        string $message,
    ): Conversation {
        $applicantUser = $profile->getUser();
        if (!$applicantUser instanceof User) {
            throw new \DomainException('Conversation impossible à initialiser.');
        }

        $existingConversation = $this->conversationRepository->findOneBetweenUsers($applicantUser, $recruiterUser);
        if ($existingConversation instanceof Conversation) {
            throw new \DomainException('Vous avez déjà initié une conversation avec ce postulant. Utilisez la messagerie pour continuer cet échange.');
        }

        $formattedFirstMessage = sprintf(
            "Nom du recruteur: %s\nEmail du recruteur: %s\n\n%s",
            '' !== $recruiterName ? $recruiterName : ($recruiterUser->getEmail() ?? 'Recruteur'),
            $recruiterEmail,
            $message,
        );

        $conversation = new Conversation();
        $conversation
            ->setApplicantUser($applicantUser)
            ->setRecruiterUser($recruiterUser)
            ->setSubject('' !== $subject ? $subject : 'Nouvelle opportunité')
            ->setStatus(ConversationStatus::OPEN)
            ->setUpdatedAt(new \DateTimeImmutable());

        $firstMessage = new Message();
        $firstMessage
            ->setConversation($conversation)
            ->setSenderUser($recruiterUser)
            ->setContent($formattedFirstMessage)
            ->setIsRead(false);

        $this->entityManager->persist($conversation);
        $this->entityManager->persist($firstMessage);
        $this->notificationManager->notifyConversationNewMessage($firstMessage);
        $this->entityManager->flush();
        $this->chatMercure->publishMessage($firstMessage);

        $this->sendApplicantNotification($profile, $conversation, $recruiterName, $recruiterEmail, $message);

        return $conversation;
    }

    private function sendApplicantNotification(
        DeveloperProfile $profile,
        Conversation $conversation,
        string $recruiterName,
        string $recruiterEmail,
        string $message,
    ): void {
        $applicantUser = $profile->getUser();
        if (!$applicantUser instanceof User || null === $applicantUser->getEmail()) {
            return;
        }

        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->from(new Address('no-reply@devspot.software', 'DevSpot Mail Bot'))
                    ->to((string) $applicantUser->getEmail())
                    ->subject(sprintf('Nouveau message recruteur: %s', (string) $conversation->getSubject()))
                    ->htmlTemplate('emails/new_conversation_applicant.html.twig')
                    ->context([
                        'applicantName' => trim(sprintf('%s %s', (string) $profile->getFirstName(), (string) $profile->getLastName())),
                        'recruiterName' => '' !== $recruiterName ? $recruiterName : ($recruiterEmail !== '' ? $recruiterEmail : 'Un recruteur'),
                        'recruiterEmail' => $recruiterEmail,
                        'conversationSubject' => (string) $conversation->getSubject(),
                        'messagePreview' => $message,
                        'messagesUrl' => $this->urlGenerator->generate('app_applicant_message_show', ['conversationId' => $conversation->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    ])
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Envoi email de contact recruteur échoué.', [
                'conversationId' => $conversation->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
