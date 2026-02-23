<?php

namespace App\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Form\DeveloperProfileType;
use App\Repository\DeveloperProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApplicantController extends AbstractController
{
    #[Route('/applicant', name: 'app_applicant_home')]
    #[IsGranted('ROLE_APPLICANT')]
    public function home(): Response
    {
        return $this->render('applicant/home.html.twig');
    }

    #[Route('/applicant/profile/create', name: 'app_applicant_profile_create')]
    #[IsGranted('ROLE_APPLICANT')]
    public function createProfile(
        Request $request,
        EntityManagerInterface $entityManager,
        DeveloperProfileRepository $developerProfileRepository,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (null !== $user->getDeveloperProfile()) {
            $this->addFlash('info', 'Ton profil developpeur existe déja.');

            return $this->redirectToRoute('app_applicant_home');
        }

        $profile = new DeveloperProfile();
        $form = $this->createForm(DeveloperProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUser($user);
            $user->setDeveloperProfile($profile);
            $profile->setIsPublic(false);
            $profile->setSlug($this->generateProfileSlug($profile, $developerProfileRepository));

            $entityManager->persist($profile);
            $entityManager->flush();

            $this->addFlash('success', 'Profil développeur créé.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/create_profile.html.twig', [
            'profileForm' => $form,
        ]);
    }

    private function generateProfileSlug(DeveloperProfile $profile, DeveloperProfileRepository $developerProfileRepository): string
    {
        $firstName = $this->slugifyPart((string) $profile->getFirstName());
        $lastName = $this->slugifyPart((string) $profile->getLastName());
        $base = $firstName.$lastName;

        if ('' === $base) {
            $base = 'profil';
        }

        $slug = $base;
        $i = 2;

        while (null !== $developerProfileRepository->findOneBy(['slug' => $slug])) {
            $slug = $base.$i;
            ++$i;
        }

        return $slug;
    }

    private function slugifyPart(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = false === $ascii ? $value : $ascii;
        $ascii = strtolower(trim($ascii));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }
}
