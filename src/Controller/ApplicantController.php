<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\User;
use App\Form\DeveloperProfileStep1Type;
use App\Form\DeveloperProfileStep2Type;
use App\Form\DeveloperProfileStep3Type;
use App\Form\DeveloperProfileStep4Type;
use App\Repository\ContactMessageRepository;
use App\Repository\DeveloperProfileRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
            'portfolioGenerated' => $profile instanceof DeveloperProfile && null !== $profile->getPortfolioGeneratedAt(),
        ]);
    }

    #[Route('/applicant/messages', name: 'app_applicant_messages')]
    #[IsGranted('ROLE_APPLICANT')]
    public function messages(ContactMessageRepository $contactMessageRepository): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        return $this->render('applicant/messages.html.twig', [
            'profile' => $profile,
            'messages' => $contactMessageRepository->findByDeveloperProfileOrdered($profile),
        ]);
    }

    #[Route('/applicant/messages/{id}', name: 'app_applicant_message_show', requirements: ['id' => '\\d+'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function showMessage(int $id, ContactMessageRepository $contactMessageRepository, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $message = $contactMessageRepository->findOneForDeveloperProfile($id, $profile);

        if (!$message instanceof ContactMessage) {
            throw $this->createNotFoundException('Ce message est introuvable.');
        }

        if (!$message->isRead()) {
            $message->setIsRead(true);
            $entityManager->flush();
        }

        return $this->render('applicant/message_show.html.twig', [
            'profile' => $profile,
            'message' => $message,
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

        $profile = $user->getDeveloperProfile() ?? new DeveloperProfile();
        $isNewProfile = null === $profile->getId();
        $form = $this->createForm(DeveloperProfileStep1Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNewProfile) {
                $profile->setUser($user);
                $user->setDeveloperProfile($profile);
                $profile->setIsPublic(false);
                $profile->setSlug($this->generateProfileSlug($profile, $developerProfileRepository));
                $entityManager->persist($profile);
            }

            $this->handleAvatarUpload($form, $profile);
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step2',
                $isNewProfile ? 'Profil développeur créé. Étape 1 terminée.' : 'Étape 1 mise à jour.',
                'Ton profil a été enregistré et tu es revenu au dashboard.'
            );
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
            $this->handleAvatarUpload($form, $profile);
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step2',
                'Étape 1 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.'
            );
        }

        return $this->render('applicant/profile_step1.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/avatar/delete', name: 'app_applicant_avatar_delete', methods: ['POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function deleteAvatar(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('delete_avatar', (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }

        $this->removePreviousAvatar($profile, $this->getParameter('kernel.project_dir') . '/public/uploads/avatars');
        $profile->setAvatarPath(null);
        $entityManager->flush();

        return new JsonResponse(['success' => true]);
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
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step3',
                'Étape 2 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.'
            );
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

        $checklist = $this->buildChecklist($profile);
        if (!$checklist['step2']['done']) {
            $this->addFlash('info', 'Impossible de passer à l\'étape 3 : il faut au moins une compétence et une formation.');

            return $this->redirectToRoute('app_applicant_profile_step2');
        }

        $form = $this->createForm(DeveloperProfileStep3Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step4',
                'Étape 3 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.'
            );
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
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_home',
                'Étape 4 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.'
            );
        }

        return $this->render('applicant/profile_step4.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/portfolio/generate', name: 'app_applicant_portfolio_generate')]
    #[IsGranted('ROLE_APPLICANT')]
    public function generatePortfolio(EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        $isComplete = !in_array(false, array_column($checklist, 'done'), true);

        if (!$isComplete) {
            $this->addFlash('info', 'Complète les 4 étapes avant de générer ton portfolio.');

            return $this->redirectToRoute('app_applicant_home');
        }

        if (null === $profile->getPortfolioGeneratedAt()) {
            $profile->setPortfolioGeneratedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        return $this->render('applicant/portfolio_generating.html.twig', [
            'portfolioUrl' => $this->generateUrl('app_public_profile_show', ['slug' => $profile->getSlug()]),
        ]);
    }

    #[Route('/applicant/cv/generate', name: 'app_applicant_cv_generate')]
    #[IsGranted('ROLE_APPLICANT')]
    public function generateCv(): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        $isComplete = !in_array(false, array_column($checklist, 'done'), true);

        if (!$isComplete) {
            $this->addFlash('info', 'Complète les 4 étapes avant de générer ton CV.');

            return $this->redirectToRoute('app_applicant_home');
        }

        return $this->render('applicant/cv_generating.html.twig', [
            'cvUrl' => $this->generateUrl('app_applicant_cv_download'),
            'returnUrl' => $this->generateUrl('app_applicant_home'),
        ]);
    }

    #[Route('/applicant/cv/download', name: 'app_applicant_cv_download')]
    #[IsGranted('ROLE_APPLICANT')]
    public function downloadCv(EntityManagerInterface $entityManager): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        $isComplete = !in_array(false, array_column($checklist, 'done'), true);

        if (!$isComplete) {
            $this->addFlash('info', 'Complète les 4 étapes avant de générer ton CV.');

            return $this->redirectToRoute('app_applicant_home');
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

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $html = $this->renderView('applicant/cv_pdf.html.twig', [
            'profile' => $profile,
            'experiences' => $experiences,
            'education' => $education,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $pdf = $dompdf->output();

        $uploadDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/cv';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
            throw new \RuntimeException('Le dossier de génération des CV est introuvable.');
        }

        $safeSlug = $profile->getSlug() ?: 'profil';
        $fileName = sprintf('%s-cv.pdf', $safeSlug);
        $filePath = $uploadDirectory.'/'.$fileName;
        file_put_contents($filePath, $pdf);

        $profile->setCvPdfPath('uploads/cv/'.$fileName);
        $entityManager->flush();

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/applicant/profile/visibility', name: 'app_applicant_profile_visibility', methods: ['POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function toggleProfileVisibility(Request $request, EntityManagerInterface $entityManager): Response
    {
        $isAsync = $request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json');
        $profile = $this->getApplicantUser()->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            if ($isAsync) {
                return new JsonResponse([
                    'success' => false,
                    'type' => 'info',
                    'message' => 'Tu dois d\'abord créer ton profil développeur.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        if (!$this->isCsrfTokenValid('toggle_visibility', (string) $request->request->get('_token'))) {
            if ($isAsync) {
                return new JsonResponse([
                    'success' => false,
                    'type' => 'error',
                    'message' => 'Action invalide, merci de réessayer.',
                ], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('info', 'Action invalide, merci de réessayer.');

            return $this->redirectToRoute('app_applicant_home');
        }

        $makePublic = '1' === (string) $request->request->get('is_public');

        if ($makePublic && null === $profile->getPortfolioGeneratedAt()) {
            if ($isAsync) {
                return new JsonResponse([
                    'success' => false,
                    'type' => 'info',
                    'message' => 'Génère d\'abord ton portfolio avant de le rendre public.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('info', 'Génère d\'abord ton portfolio avant de le rendre public.');

            return $this->redirectToRoute('app_applicant_home');
        }

        $profile->setIsPublic($makePublic);
        $entityManager->flush();

        $message = $makePublic ? 'Ton portfolio est maintenant public.' : 'Ton portfolio est maintenant privé.';

        if ($isAsync) {
            return new JsonResponse([
                'success' => true,
                'type' => 'success',
                'message' => $message,
                'isPublic' => $makePublic,
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_applicant_home');
    }

    private function getApplicantUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectAfterProfileStep(Request $request, string $nextRoute, string $nextMessage, string $exitMessage): Response
    {
        if ('save_and_exit' === (string) $request->request->get('form_action')) {
            $this->addFlash('success', $exitMessage);

            return $this->redirectToRoute('app_applicant_home');
        }

        $this->addFlash('success', $nextMessage);

        return $this->redirectToRoute($nextRoute);
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
                'route' => 'app_applicant_profile_step1',
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

    private function handleAvatarUpload(FormInterface $form, DeveloperProfile $profile): void
    {
        $avatarFile = $form->get('avatarFile')->getData();
        if (!$avatarFile instanceof UploadedFile) {
            return;
        }

        $uploadDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/avatars';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
            throw new \RuntimeException('Le dossier de téléversement des avatars est introuvable.');
        }

        $this->removePreviousAvatar($profile, $uploadDirectory);

        $extension = strtolower($avatarFile->getClientOriginalExtension());
        if ('' === $extension) {
            $extension = strtolower(pathinfo($avatarFile->getClientOriginalName(), PATHINFO_EXTENSION));
        }
        if ('' === $extension) {
            $extension = 'bin';
        }
        $slug = $profile->getSlug() ?: 'profil';
        $fileName = sprintf('%s-%s.%s', $slug, substr(bin2hex(random_bytes(4)), 0, 8), strtolower($extension));

        try {
            $avatarFile->move($uploadDirectory, $fileName);
        } catch (FileException $exception) {
            throw new \RuntimeException('Impossible d\'enregistrer la photo de profil.', 0, $exception);
        }

        $profile->setAvatarPath('uploads/avatars/'.$fileName);
    }

    private function removePreviousAvatar(DeveloperProfile $profile, string $uploadDirectory): void
    {
        $currentAvatarPath = $profile->getAvatarPath();
        if (null === $currentAvatarPath || !str_starts_with($currentAvatarPath, 'uploads/avatars/')) {
            return;
        }

        $currentFilePath = $this->getParameter('kernel.project_dir').'/public/'.$currentAvatarPath;
        if (is_file($currentFilePath) && str_starts_with(dirname($currentFilePath), $uploadDirectory)) {
            @unlink($currentFilePath);
        }
    }
}
