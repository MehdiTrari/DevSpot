<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Form\ContactMessageType;
use App\Repository\ContactMessageRepository;
use App\Repository\DeveloperProfileRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    private const CONTACT_COOLDOWN_DAYS = 3;
    private const DAILY_DISTINCT_CONTACT_LIMIT = 20;

    #[Route('/profil/{slug}', name: 'app_public_profile_show', methods: ['GET', 'POST'])]
    public function show(
        string $slug,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        ContactMessageRepository $contactMessageRepository,
        #[Autowire(service: 'html_sanitizer.sanitizer.contact_message')]
        HtmlSanitizerInterface $contactMessageSanitizer,
        NotificationManager $notificationManager,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $profile = $developerProfileRepository->findPublicPortfolioBySlugWithDetails($slug);

        if (!$profile instanceof DeveloperProfile) {
            throw $this->createNotFoundException('Aucun profil ne correspond à cette URL.');
        }

        if (null === $profile->getPortfolioGeneratedAt()) {
            throw $this->createAccessDeniedException('Ce portfolio n\'a pas encore été généré.');
        }

        if (!$profile->isPublic() && !$this->isOwner($profile)) {
            throw $this->createAccessDeniedException('Ce profil est privé.');
        }

        if (!$profile->isPublic() && $request->isMethod('POST')) {
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

        if (($contactRequiresLogin || $contactRequiresRecruiterRole) && $request->isMethod('POST')) {
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
                    $recruiterEmail = mb_strtolower(trim((string) ($currentUser instanceof User ? $currentUser->getEmail() : '')));

                    if ('' === $recruiterEmail) {
                        $contactForm->addError(new FormError('Impossible d\'envoyer le message sans email recruteur valide.'));
                    }

                    $latestContact = $contactMessageRepository->findLatestForRecruiterAndProfile($profile, $recruiterEmail);
                    if ($latestContact instanceof ContactMessage) {
                        $nextAllowedAt = $latestContact->getCreatedAt()?->modify(sprintf('+%d days', self::CONTACT_COOLDOWN_DAYS));
                        if ($nextAllowedAt instanceof \DateTimeImmutable && $nextAllowedAt > new \DateTimeImmutable()) {
                            $contactForm->addError(new FormError(sprintf(
                                'Vous avez déjà contacté ce candidat récemment. Merci d\'attendre %d jour(s) entre deux prises de contact.',
                                self::CONTACT_COOLDOWN_DAYS
                            )));
                        }
                    }

                    $dayStart = new \DateTimeImmutable('today');
                    $dayEnd = $dayStart->modify('+1 day');
                    $dailyDistinctCount = $contactMessageRepository->countDistinctProfilesContactedByRecruiterBetween($recruiterEmail, $dayStart, $dayEnd);

                    if ($dailyDistinctCount >= self::DAILY_DISTINCT_CONTACT_LIMIT) {
                        $contactForm->addError(new FormError(sprintf(
                            'Limite atteinte: vous ne pouvez pas contacter plus de %d candidats différents sur une même journée.',
                            self::DAILY_DISTINCT_CONTACT_LIMIT
                        )));
                    }

                    $contactMessage->setDeveloperProfile($profile);
                    $contactMessage->setIsRead(false);
                    if (null === $contactMessage->getSubject()) {
                        $contactMessage->setSubject('');
                    }

                    $entityManager->persist($contactMessage);
                    $notificationManager->notifyApplicantNewMessage($contactMessage);
                    $entityManager->flush();

                    $this->addFlash('success', 'Votre message a bien été envoyé au développeur.');

                    return $this->redirectToRoute('app_public_profile_show', [
                        'slug' => $profile->getSlug(),
                    ]);
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
            'isOwner' => $this->isOwner($profile),
            'contactRequiresLogin' => $contactRequiresLogin,
            'contactRequiresRecruiterRole' => $contactRequiresRecruiterRole,
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

    private function isOwner(DeveloperProfile $profile): bool
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $user->getDeveloperProfile()?->getId() === $profile->getId();
    }
}
