<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Form\ContactMessageType;
use App\Repository\ConversationRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\FavoriteProfileRepository;
use App\Service\RecruiterConversationStarter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/profil/{slug}', name: 'app_public_profile_show', methods: ['GET', 'POST'])]
    public function show(
        string $slug,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        ConversationRepository $conversationRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        #[Autowire(service: 'html_sanitizer.sanitizer.contact_message')]
        HtmlSanitizerInterface $contactMessageSanitizer,
        RecruiterConversationStarter $recruiterConversationStarter,
    ): Response {
        $profile = $developerProfileRepository->findPublicPortfolioBySlugWithDetails($slug);

        if (!$profile instanceof DeveloperProfile) {
            throw $this->createNotFoundException('Aucun profil ne correspond à cette URL.');
        }

        $isOwner = $this->isOwner($profile);
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $profileStatus = $profile->getUser()?->getStatus();

        if (!$isOwner && !$isAdmin && UserStatus::ACTIVE !== $profileStatus) {
            throw $this->createNotFoundException('Aucun profil ne correspond à cette URL.');
        }

        if (null === $profile->getPortfolioGeneratedAt() && !$isAdmin) {
            throw $this->createAccessDeniedException('Ce portfolio n\'a pas encore été généré.');
        }

        if (!$profile->isPublic() && !$isOwner && !$isAdmin) {
            throw $this->createAccessDeniedException('Ce profil est privé.');
        }

        if (!$profile->isPublic() && !$isAdmin && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException('Impossible d\'envoyer un message à un profil privé.');
        }

        $currentUser = $this->getUser();
        $isRecruiter = $this->isGranted('ROLE_RECRUITER');
        $isAnonymousPublicGet = $profile->isPublic() && !$currentUser instanceof User && $request->isMethod('GET');

        if ($isAnonymousPublicGet) {
            $lastModified = $profile->getUpdatedAt() ?? $profile->getCreatedAt() ?? new \DateTimeImmutable('@0');
            $cacheProbeResponse = new Response();
            $cacheProbeResponse->setPublic();
            $cacheProbeResponse->setSharedMaxAge(120);
            $cacheProbeResponse->setMaxAge(120);
            $cacheProbeResponse->setLastModified($lastModified);
            $cacheProbeResponse->setEtag(sprintf(
                'public-profile-%d-%d-%d-%d',
                (int) ($profile->getId() ?? 0),
                (int) $profile->isPublic(),
                $lastModified->getTimestamp(),
                $profile->getPortfolioGeneratedAt()?->getTimestamp() ?? 0
            ));

            if ($cacheProbeResponse->isNotModified($request)) {
                return $cacheProbeResponse;
            }
        }

        $contactRequiresLogin = $profile->isPublic() && !$currentUser instanceof User;
        $contactRequiresRecruiterRole = $profile->isPublic() && $currentUser instanceof User && !$isRecruiter;
        $alreadyContactedDeveloper = false;
        $existingConversation = null;
        $isFavorite = false;

        if ($profile->isPublic() && $currentUser instanceof User && $isRecruiter && $profile->getUser() instanceof User) {
            $existingConversation = $conversationRepository->findOneBetweenUsers($profile->getUser(), $currentUser);
            $alreadyContactedDeveloper = $existingConversation instanceof Conversation;

            $recruiterProfile = $currentUser->getRecruiterProfile();
            if (null !== $recruiterProfile) {
                $isFavorite = null !== $favoriteProfileRepository->findOneForRecruiterAndDeveloperProfile($recruiterProfile, $profile);
            }
        }

        if (($contactRequiresLogin || $contactRequiresRecruiterRole) && !$isAdmin && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException('Seuls les recruteurs peuvent contacter ce développeur.');
        }

        $contactFormView = null;
        if ($profile->isPublic() && !$contactRequiresLogin && !$contactRequiresRecruiterRole) {
            $contactMessage = new ContactMessage();

            if ($currentUser instanceof User && null !== $currentUser->getEmail()) {
                $contactMessage->setRecruiterEmail($currentUser->getEmail());
            }

            $contactForm = $this->createForm(ContactMessageType::class, $contactMessage);
            $contactForm->handleRequest($request);

            if ($contactForm->isSubmitted() && $contactForm->isValid()) {
                $sanitizedRecruiterName = trim($contactMessageSanitizer->sanitize((string) $contactMessage->getRecruiterName()));
                $sanitizedSubject = trim($contactMessageSanitizer->sanitize((string) ($contactMessage->getSubject() ?? '')));
                $sanitizedMessage = trim($contactMessageSanitizer->sanitize((string) $contactMessage->getMessage()));

                $contactMessage->setRecruiterName($sanitizedRecruiterName);
                $contactMessage->setSubject($sanitizedSubject);
                $contactMessage->setMessage($sanitizedMessage);

                if ('' === $sanitizedRecruiterName) {
                    $contactForm->get('recruiterName')->addError(new FormError('Le nom contient trop de contenu HTML non autorisé.'));
                }

                if (mb_strlen($sanitizedMessage) < 10) {
                    $contactForm->get('message')->addError(new FormError('Le message contient trop de contenu HTML non autorisé.'));
                }

                if ($contactForm->isValid()) {
                    if ($alreadyContactedDeveloper) {
                        $contactForm->addError(new FormError('Vous avez déjà initié une conversation avec ce postulant. Utilisez la messagerie pour continuer cet échange.'));
                    }

                    if (!$currentUser instanceof User || !$profile->getUser() instanceof User) {
                        $contactForm->addError(new FormError('Conversation impossible à initialiser.'));
                    }

                    if ($contactForm->isValid()) {
                        try {
                            $recruiterConversationStarter->startConversation(
                                $profile,
                                $currentUser,
                                $sanitizedRecruiterName,
                                (string) ($contactMessage->getRecruiterEmail() ?? $currentUser->getEmail() ?? ''),
                                $sanitizedSubject,
                                $sanitizedMessage,
                            );
                        } catch (\DomainException $exception) {
                            $contactForm->addError(new FormError($exception->getMessage()));
                        }

                        if ($contactForm->isValid()) {
                            $this->addFlash('success', 'Votre message a bien été envoyé au développeur.');

                            return $this->redirectToRoute('app_public_profile_show', [
                                'slug' => $profile->getSlug(),
                            ]);
                        }
                    }
                }
            }

            $contactFormView = $contactForm->createView();
        }

        $experiences = $profile->getExperiences()->toArray();
        usort(
            $experiences,
            static fn ($left, $right) => ($right->getStartDate()?->getTimestamp() ?? 0) <=> ($left->getStartDate()?->getTimestamp() ?? 0)
        );

        $education = $profile->getEducation()->toArray();
        usort(
            $education,
            static fn ($left, $right) => ($right->getStartDate()?->getTimestamp() ?? 0) <=> ($left->getStartDate()?->getTimestamp() ?? 0)
        );

        $technologyNames = [];
        foreach ($experiences as $experience) {
            foreach ($experience->getTechnologies() as $technology) {
                $name = trim((string) $technology->getName());
                if ('' !== $name) {
                    $technologyNames[$name] = $name;
                }
            }
        }
        ksort($technologyNames);

        $response = $this->render('profile/show.html.twig', [
            'profile' => $profile,
            'experiences' => $experiences,
            'education' => $education,
            'technologyNames' => array_values($technologyNames),
            'isOwner' => $isOwner,
            'contactRequiresLogin' => $contactRequiresLogin,
            'contactRequiresRecruiterRole' => $contactRequiresRecruiterRole,
            'alreadyContactedDeveloper' => $alreadyContactedDeveloper,
            'isFavorite' => $isFavorite,
            'contactForm' => $contactFormView,
        ]);

        if ($isAnonymousPublicGet) {
            $lastModified = $profile->getUpdatedAt() ?? $profile->getCreatedAt() ?? new \DateTimeImmutable('@0');
            $response->setPublic();
            $response->setSharedMaxAge(120);
            $response->setMaxAge(120);
            $response->setLastModified($lastModified);
            $response->setEtag(sprintf(
                'public-profile-%d-%d-%d-%d',
                (int) ($profile->getId() ?? 0),
                (int) $profile->isPublic(),
                $lastModified->getTimestamp(),
                $profile->getPortfolioGeneratedAt()?->getTimestamp() ?? 0
            ));
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store', true);
        }

        return $response;
    }

    #[Route('/profil/{slug}/report', name: 'app_public_profile_report', methods: ['POST'])]
    public function report(
        string $slug,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        NotificationManager $notificationManager,
        EntityManagerInterface $entityManager,
    ): Response {
        $profile = $developerProfileRepository->findPublicPortfolioBySlugWithDetails($slug);

        if (!$profile instanceof DeveloperProfile) {
            throw $this->createNotFoundException('Aucun profil ne correspond à cette URL.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour signaler un profil.');
        }

        if ($this->isOwner($profile)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas signaler votre propre profil.');
        }

        if (!$this->isCsrfTokenValid('report_profile_' . $profile->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_public_profile_show', ['slug' => $profile->getSlug()]);
        }

        $category = (string) $request->request->get('category', 'profile');
        $reason = trim((string) $request->request->get('reason', ''));
        if (mb_strlen($reason) < 10) {
            $this->addFlash('error', 'Merci de préciser un motif d\'au moins 10 caractères.');

            return $this->redirectToRoute('app_public_profile_show', ['slug' => $profile->getSlug()]);
        }

        $created = $notificationManager->notifyAdminsProfileReported(
            $user,
            $profile,
            $reason,
            'abusive_content' === $category
        );

        if ($created) {
            $entityManager->flush();
            $this->addFlash('success', 'Votre signalement a été transmis aux administrateurs.');
        } else {
            $this->addFlash('info', 'Un signalement identique est déjà en attente de traitement.');
        }

        return $this->redirectToRoute('app_public_profile_show', ['slug' => $profile->getSlug()]);
    }

    private function isOwner(DeveloperProfile $profile): bool
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $user->getDeveloperProfile()?->getId() === $profile->getId();
    }
}
