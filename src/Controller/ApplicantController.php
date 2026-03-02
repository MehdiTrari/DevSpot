<?php

namespace App\Controller;

use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Form\DeveloperProfileStep1Type;
use App\Form\DeveloperProfileStep2Type;
use App\Form\DeveloperProfileStep3Type;
use App\Form\DeveloperProfileStep4Type;
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
        $user = $this->getApplicantUser();
        $profile = $user->getDeveloperProfile();

        return $this->render('applicant/dashboard.html.twig', [
            'profile' => $profile,
            'checklist' => $this->buildChecklist($profile),
        ]);
    }

    #[Route('/applicant/profile/create', name: 'app_applicant_profile_create')]
    #[IsGranted('ROLE_APPLICANT')]
    public function createProfile(
        Request $request,
        EntityManagerInterface $entityManager,
        DeveloperProfileRepository $developerProfileRepository,
    ): Response {
        $user = $this->getApplicantUser();

        if (null !== $user->getDeveloperProfile()) {
            $this->addFlash('info', 'Ton profil développeur existe déjà.');

            return $this->redirectToRoute('app_applicant_profile_step1');
        }

        $profile = new DeveloperProfile();
        $form = $this->createForm(DeveloperProfileStep1Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUser($user);
            $user->setDeveloperProfile($profile);
            $profile->setIsPublic(false);
            $profile->setSlug($this->generateProfileSlug($profile, $developerProfileRepository));

            $entityManager->persist($profile);
            $entityManager->flush();

            $this->addFlash('success', 'Profil développeur créé. Étape 1 terminée.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/create_profile.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-1', name: 'app_applicant_profile_step1')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep1(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $form = $this->createForm(DeveloperProfileStep1Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Étape 1 mise à jour.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/profile_step1.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-2', name: 'app_applicant_profile_step2')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep2(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $form = $this->createForm(DeveloperProfileStep2Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Étape 2 mise à jour.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/profile_step2.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-3', name: 'app_applicant_profile_step3')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep3(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $form = $this->createForm(DeveloperProfileStep3Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Étape 3 mise à jour.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/profile_step3.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-4', name: 'app_applicant_profile_step4')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep4(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $form = $this->createForm(DeveloperProfileStep4Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Étape 4 mise à jour.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/profile_step4.html.twig', [
            'profileForm' => $form,
        ]);
    }

    private function getApplicantUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array<string, array{done: bool, route: string, label: string}>
     */
    private function buildChecklist(?DeveloperProfile $profile): array
    {
        $step1Done = false;
        $step2Done = false;
        $step3Done = false;
        $step4Done = false;

        if ($profile instanceof DeveloperProfile) {
            $step1Done =
                '' !== trim((string) ($profile->getFirstName() ?? '')) &&
                '' !== trim((string) ($profile->getLastName() ?? '')) &&
                '' !== trim((string) ($profile->getHeadline() ?? '')) &&
                '' !== trim((string) ($profile->getCity() ?? '')) &&
                '' !== trim((string) ($profile->getCountry() ?? '')) &&
                null !== $profile->getLocationType() &&
                null !== $profile->getExperienceLevel() &&
                null !== $profile->getYearsExperience() &&
                '' !== trim((string) ($profile->getBio() ?? ''));

            $step2Done =
                $profile->getExperiences()->count() > 0 &&
                $profile->getEducation()->count() > 0 &&
                $profile->getProfileSkills()->count() > 0;

            $step3Done =
                '' !== trim((string) ($profile->getGithubUrl() ?? '')) ||
                '' !== trim((string) ($profile->getLinkedinUrl() ?? '')) ||
                '' !== trim((string) ($profile->getPortfolioUrl() ?? ''));

            $step4Done = $profile->getDesiredPositions()->count() > 0;
        }

        return [
            'step1' => [
                'done' => $step1Done,
                'route' => null === $profile ? 'app_applicant_profile_create' : 'app_applicant_profile_step1',
                'label' => 'Étape 1 - Infos générales',
            ],
            'step2' => [
                'done' => $step2Done,
                'route' => 'app_applicant_profile_step2',
                'label' => 'Étape 2 - Expériences, éducation, skills',
            ],
            'step3' => [
                'done' => $step3Done,
                'route' => 'app_applicant_profile_step3',
                'label' => 'Étape 3 - Liens externes',
            ],
            'step4' => [
                'done' => $step4Done,
                'route' => 'app_applicant_profile_step4',
                'label' => 'Étape 4 - Postes recherchés',
            ],
        ];
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
