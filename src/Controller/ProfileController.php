<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Form\ContactMessageType;
use App\Repository\DeveloperProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        EntityManagerInterface $entityManager,
    ): Response
    {
        $profile = $developerProfileRepository->findOneBy(['slug' => $slug]);

        if (!$profile instanceof DeveloperProfile) {
            throw $this->createNotFoundException('Aucun profil ne correspond à cette URL.');
        }

        if (null === $profile->getPortfolioGeneratedAt()) {
            throw $this->createAccessDeniedException('Ce portfolio n\'a pas encore été généré.');
        }

        if (!$profile->isPublic() && !$this->isOwner($profile)) {
            return $this->render('bundles/TwigBundle/Exception/error403.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        if (!$profile->isPublic() && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException('Impossible d envoyer un message a un profil prive.');
        }

        $contactFormView = null;
        if ($profile->isPublic()) {
            $contactMessage = new ContactMessage();

            $currentUser = $this->getUser();
            if ($currentUser instanceof User && null !== $currentUser->getEmail()) {
                $contactMessage->setRecruiterEmail($currentUser->getEmail());
            }

            $contactForm = $this->createForm(ContactMessageType::class, $contactMessage);
            $contactForm->handleRequest($request);

            if ($contactForm->isSubmitted() && $contactForm->isValid()) {
                $contactMessage->setDeveloperProfile($profile);
                $contactMessage->setIsRead(false);
                if (null === $contactMessage->getSubject()) {
                    $contactMessage->setSubject('');
                }

                $entityManager->persist($contactMessage);
                $entityManager->flush();

                $this->addFlash('success', 'Votre message a bien ete envoye au developpeur.');

                return $this->redirectToRoute('app_public_profile_show', [
                    'slug' => $profile->getSlug(),
                ]);
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

        return $this->render('profile/show.html.twig', [
            'profile' => $profile,
            'experiences' => $experiences,
            'education' => $education,
            'technologyNames' => array_values($technologyNames),
            'isOwner' => $this->isOwner($profile),
            'contactForm' => $contactFormView,
        ]);
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
