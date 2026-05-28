<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\JobOffer;
use App\Entity\Message;
use App\Entity\User;
use App\Form\ChatReplyType;
use App\Form\DeveloperProfileStep1Type;
use App\Form\DeveloperProfileStep2Type;
use App\Form\DeveloperProfileStep3Type;
use App\Form\DeveloperProfileStep4Type;
use App\Repository\ConversationRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\JobOfferRepository;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use App\Service\LoggerService;
use App\Service\NotificationManager;
use App\Service\OfferLifecycleManager;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApplicantController extends AbstractController
{
    #[Route('/applicant', name: 'app_applicant_home')]
    #[Route('/applicant/dashboard', name: 'app_applicant_dashboard')]
    #[IsGranted('ROLE_APPLICANT')]
    public function home(
        NotificationManager $notificationManager,
        EntityManagerInterface $entityManager,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        JobOfferRepository $jobOfferRepository,
        OfferLifecycleManager $offerLifecycleManager,
    ): Response {
        $offerLifecycleManager->expireDueOffers();

        $user = $this->getApplicantUser();
        $profile = $user->getDeveloperProfile();
        $checklist = $this->buildChecklist($profile);

        if ($notificationManager->notifyApplicantIncompleteProfileReminder($user, $checklist)) {
            $entityManager->flush();
        }

        $conversationRows = $this->buildApplicantConversationRows($conversationRepository, $messageRepository, $user);
        $dashboard = $this->buildApplicantDashboard(
            $user,
            $profile,
            $checklist,
            $conversationRows,
            $messageRepository,
            $favoriteProfileRepository,
            $jobOfferRepository,
        );

        return $this->render('applicant/dashboard.html.twig', [
            'profile' => $profile,
            'checklist' => $checklist,
            'dashboard' => $dashboard,
            'conversationRows' => $conversationRows,
            'portfolioGenerated' => $profile instanceof DeveloperProfile && null !== $profile->getPortfolioGeneratedAt(),
        ]);
    }

    #[Route('/applicant/messages', name: 'app_applicant_messages')]
    #[IsGranted('ROLE_APPLICANT')]
    public function messages(
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        ChatMercure $chatMercure,
    ): Response {
        $user = $this->getApplicantUser();
        $profile = $user->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $conversationRows = $this->buildApplicantConversationRows($conversationRepository, $messageRepository, $user);

        return $this->render('applicant/messages.html.twig', [
            'profile' => $profile,
            'conversations' => $conversationRows,
            'mercureTopics' => $chatMercure->getTopicsForUser($user),
            'mercureNeedsCredentials' => $chatMercure->requiresCredentials(),
            'selectedConversation' => null,
            'selectedMessages' => [],
            'selectedRecruiterName' => null,
            'selectedRecruiterEmail' => null,
            'selectedRecruiterUser' => null,
            'replyForm' => null,
        ]);
    }

    #[Route('/applicant/messages/{conversationId}', name: 'app_applicant_message_show', requirements: ['conversationId' => '\\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function showMessage(
        int $conversationId,
        Request $request,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'html_sanitizer.sanitizer.contact_message')]
        HtmlSanitizerInterface $contactMessageSanitizer,
        NotificationManager $notificationManager,
        ChatMercure $chatMercure,
    ): Response {
        $applicantUser = $this->getApplicantUser();
        $profile = $applicantUser->getDeveloperProfile();

        if (!$profile instanceof DeveloperProfile) {
            $this->addFlash('info', 'Tu dois d\'abord créer ton profil développeur.');

            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getApplicantUser()?->getId() !== $applicantUser->getId()) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        $messageRepository->markConversationAsReadForUser($conversation, $applicantUser);

        $replyMessage = new Message();
        $replyMessage->setContent('');
        $replyForm = $this->createForm(ChatReplyType::class, $replyMessage);
        $replyForm->handleRequest($request);

        if ($replyForm->isSubmitted() && $replyForm->isValid()) {
            $sanitizedMessage = trim($contactMessageSanitizer->sanitize((string) $replyMessage->getContent()));
            $replyMessage->setContent($sanitizedMessage);

            if ('' === $sanitizedMessage) {
                $replyForm->get('content')->addError(new FormError('Le message contient trop de contenu HTML non autorisé.'));
            }

            if ($replyForm->isValid()) {
                $replyMessage
                    ->setConversation($conversation)
                    ->setSenderUser($applicantUser)
                    ->setIsRead(false)
                    ->setCreatedAt(new \DateTimeImmutable());

                $conversation->setUpdatedAt(new \DateTimeImmutable());

                $entityManager->persist($replyMessage);
                $notificationManager->notifyConversationNewMessage($replyMessage);
                $entityManager->flush();
                $chatMercure->publishMessage($replyMessage);

                if ($request->isXmlHttpRequest()) {
                    $html = $this->renderView('applicant/_chat_message.html.twig', [
                        'message' => $replyMessage,
                        'mine' => true,
                    ]);

                    return new JsonResponse([
                        'ok' => true,
                        'html' => $html,
                        'messageId' => $replyMessage->getId(),
                    ]);
                }

                $this->addFlash('success', 'Message envoyé.');

                return $this->redirectToRoute('app_applicant_message_show', [
                    'conversationId' => $conversationId,
                ]);
            }
        }

        $conversationRows = $this->buildApplicantConversationRows($conversationRepository, $messageRepository, $applicantUser);
        $conversationMessages = $messageRepository->findByConversationOrdered($conversation);
        $recruiterUser = $conversation->getRecruiterUser();
        $recruiterName = $this->resolveRecruiterDisplayName($recruiterUser);

        return $this->render('applicant/messages.html.twig', [
            'profile' => $profile,
            'conversations' => $conversationRows,
            'mercureTopics' => $chatMercure->getTopicsForUser($applicantUser, $conversation),
            'mercureNeedsCredentials' => $chatMercure->requiresCredentials(),
            'selectedConversation' => $conversation,
            'selectedMessages' => $conversationMessages,
            'selectedRecruiterEmail' => (string) ($recruiterUser?->getEmail() ?? ''),
            'selectedRecruiterName' => $recruiterName,
            'selectedRecruiterUser' => $recruiterUser,
            'replyForm' => $replyForm,
        ]);
    }

    #[Route('/applicant/messages/{conversationId}/poll', name: 'app_applicant_message_poll', requirements: ['conversationId' => '\\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function pollConversation(
        int $conversationId,
        Request $request,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
    ): JsonResponse {
        $applicantUser = $this->getApplicantUser();

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getApplicantUser()?->getId() !== $applicantUser->getId()) {
            return new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        $sinceId = max(0, (int) $request->query->get('sinceId', 0));
        $newMessages = $messageRepository->findByConversationAfterIdOrdered($conversation, $sinceId);

        $messageRepository->markConversationAsReadForUser($conversation, $applicantUser);

        $html = '';
        $lastId = $sinceId;
        foreach ($newMessages as $message) {
            $mine = $message->getSenderUser()?->getId() === $applicantUser->getId();
            $html .= $this->renderView('applicant/_chat_message.html.twig', [
                'message' => $message,
                'mine' => $mine,
            ]);
            $lastId = max($lastId, (int) ($message->getId() ?? 0));
        }

        return new JsonResponse([
            'ok' => true,
            'html' => $html,
            'lastId' => $lastId,
        ]);
    }

    #[Route('/applicant/messages/{conversationId}/read', name: 'app_applicant_message_mark_read', requirements: ['conversationId' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function markConversationRead(
        int $conversationId,
        Request $request,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        ChatMercure $chatMercure,
    ): JsonResponse {
        $applicantUser = $this->getApplicantUser();

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getApplicantUser()?->getId() !== $applicantUser->getId()) {
            return new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('chat_read_' . $conversationId, (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $updatedCount = $messageRepository->markConversationAsReadForUser($conversation, $applicantUser);
        if ($updatedCount > 0) {
            $chatMercure->publishConversationReadState($applicantUser, $conversation);
        }

        return new JsonResponse(['ok' => true, 'updated' => $updatedCount]);
    }

    #[Route('/applicant/profile/create', name: 'app_applicant_profile_create')]
    #[IsGranted('ROLE_APPLICANT')]
    public function createProfile(
        Request $request,
        EntityManagerInterface $entityManager,
        DeveloperProfileRepository $developerProfileRepository,
        NotificationManager $notificationManager,
        LoggerService $loggerService,
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
            $loggerService->log(
                LoggerService::PROFILE_UPDATE,
                $user,
                DeveloperProfile::class,
                $profile->getId(),
                ['step' => 1, 'created' => $isNewProfile],
            );

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step2',
                $isNewProfile ? 'Profil développeur créé. Étape 1 terminée.' : 'Étape 1 mise à jour.',
                'Ton profil a été enregistré et tu es revenu au dashboard.',
                $profile,
                $notificationManager
            );
        }

        return $this->render('applicant/create_profile.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-1', name: 'app_applicant_profile_step1')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep1(Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager, LoggerService $loggerService): Response
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
            $loggerService->log(
                LoggerService::PROFILE_UPDATE,
                $this->getApplicantUser(),
                DeveloperProfile::class,
                $profile->getId(),
                ['step' => 1],
                flush: false,
            );
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step2',
                'Étape 1 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.',
                $profile,
                $notificationManager
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
    public function editProfileStep2(Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager, LoggerService $loggerService): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        if (!$checklist['step1']['done']) {
            $this->addFlash('info', 'Complete d\'abord l\'etape 1 avant de passer a l\'etape 2.');

            return $this->redirectToRoute('app_applicant_profile_step1');
        }

        $form = $this->createForm(DeveloperProfileStep2Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $loggerService->log(
                LoggerService::PROFILE_UPDATE,
                $this->getApplicantUser(),
                DeveloperProfile::class,
                $profile->getId(),
                ['step' => 2],
                flush: false,
            );
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step3',
                'Étape 2 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.',
                $profile,
                $notificationManager
            );
        }

        return $this->render('applicant/profile_step2.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-3', name: 'app_applicant_profile_step3')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep3(Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager, LoggerService $loggerService): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        if (!$checklist['step1']['done']) {
            $this->addFlash('info', 'Complete d\'abord l\'etape 1 avant de passer a l\'etape 3.');

            return $this->redirectToRoute('app_applicant_profile_step1');
        }

        if (!$checklist['step2']['done']) {
            $this->addFlash('info', 'Impossible de passer à l\'étape 3 : il faut au moins une compétence et une formation.');

            return $this->redirectToRoute('app_applicant_profile_step2');
        }

        $form = $this->createForm(DeveloperProfileStep3Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $loggerService->log(
                LoggerService::PROFILE_UPDATE,
                $this->getApplicantUser(),
                DeveloperProfile::class,
                $profile->getId(),
                ['step' => 3],
                flush: false,
            );
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_profile_step4',
                'Étape 3 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.',
                $profile,
                $notificationManager
            );
        }

        return $this->render('applicant/profile_step3.html.twig', [
            'profileForm' => $form,
        ]);
    }

    #[Route('/applicant/profile/step-4', name: 'app_applicant_profile_step4')]
    #[IsGranted('ROLE_APPLICANT')]
    public function editProfileStep4(Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager, LoggerService $loggerService): Response
    {
        $profile = $this->getApplicantUser()->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            return $this->redirectToRoute('app_applicant_profile_create');
        }

        $checklist = $this->buildChecklist($profile);
        if (!$checklist['step1']['done']) {
            $this->addFlash('info', 'Complete d\'abord l\'etape 1 avant de passer a l\'etape 4.');

            return $this->redirectToRoute('app_applicant_profile_step1');
        }

        if (!$checklist['step2']['done']) {
            $this->addFlash('info', 'Complete d\'abord l\'etape 2 avant de passer a l\'etape 4.');

            return $this->redirectToRoute('app_applicant_profile_step2');
        }

        if (!$checklist['step3']['done']) {
            $this->addFlash('info', 'Complete d\'abord l\'etape 3 avant de passer a l\'etape 4.');

            return $this->redirectToRoute('app_applicant_profile_step3');
        }

        $form = $this->createForm(DeveloperProfileStep4Type::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile->setUpdatedAt(new \DateTimeImmutable());
            $loggerService->log(
                LoggerService::PROFILE_UPDATE,
                $this->getApplicantUser(),
                DeveloperProfile::class,
                $profile->getId(),
                ['step' => 4],
                flush: false,
            );
            $entityManager->flush();

            return $this->redirectAfterProfileStep(
                $request,
                'app_applicant_home',
                'Étape 4 mise à jour.',
                'Tes modifications ont été enregistrées et tu es revenu au dashboard.',
                $profile,
                $notificationManager
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

        $uploadDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/cv';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
            throw new \RuntimeException('Le dossier de génération des CV est introuvable.');
        }

        $safeSlug = $profile->getSlug() ?: 'profil';
        $fileName = sprintf('%s-cv.pdf', $safeSlug);
        $filePath = $uploadDirectory . '/' . $fileName;
        file_put_contents($filePath, $pdf);

        $profile->setCvPdfPath('uploads/cv/' . $fileName);
        $entityManager->flush();

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/applicant/profile/visibility', name: 'app_applicant_profile_visibility', methods: ['POST'])]
    #[IsGranted('ROLE_APPLICANT')]
    public function toggleProfileVisibility(Request $request, EntityManagerInterface $entityManager, NotificationManager $notificationManager): Response
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

        $notificationManager->notifyRecruitersFollowingProfileVisibilityChanged($profile, $makePublic);

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

    /**
     * @param array<string, array{done: bool, route: string, label: string}> $checklist
     * @param list<array{conversation: Conversation, recruiterUser: ?User, recruiterName: string, recruiterCompany: ?string, lastMessage: Message, lastMessagePreview: string, lastMessageAt: ?\DateTimeImmutable, unreadCount: int, status: string}> $conversationRows
     *
     * @return array<string, mixed>
     */
    private function buildApplicantDashboard(
        User $user,
        ?DeveloperProfile $profile,
        array $checklist,
        array $conversationRows,
        MessageRepository $messageRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        JobOfferRepository $jobOfferRepository,
    ): array {
        $doneCount = count(array_filter(array_column($checklist, 'done')));
        $completionPercent = $doneCount * 25;
        $activeConversationsCount = count($conversationRows);
        $unreadMessagesCount = $messageRepository->countUnreadForUser($user);
        $favoriteProfiles = $profile instanceof DeveloperProfile ? $favoriteProfileRepository->findByDeveloperProfile($profile) : [];
        $interactions = $this->buildRecruiterInteractions($conversationRows, $favoriteProfiles);
        $recommendedOffers = $profile instanceof DeveloperProfile
            ? $this->buildCompatibleOfferRows($profile, $jobOfferRepository->findActiveForMatching())
            : [];
        $visibilityScore = $this->estimateProfileVisibilityScore(
            $profile,
            $completionPercent,
            count($interactions),
            $activeConversationsCount,
        );

        return [
            'completionPercent' => $completionPercent,
            'doneCount' => $doneCount,
            'skills' => $this->buildSkillRows($profile),
            'experiences' => $this->buildExperienceRows($profile),
            'education' => $this->buildEducationRows($profile),
            'skillsCount' => $profile instanceof DeveloperProfile ? $profile->getProfileSkills()->count() : 0,
            'experiencesCount' => $profile instanceof DeveloperProfile ? $profile->getExperiences()->count() : 0,
            'educationCount' => $profile instanceof DeveloperProfile ? $profile->getEducation()->count() : 0,
            'hasCv' => $profile instanceof DeveloperProfile && null !== $profile->getCvPdfPath(),
            'hasPortfolio' => $profile instanceof DeveloperProfile && null !== $profile->getPortfolioGeneratedAt(),
            'desiredPositions' => $this->buildDesiredPositionRows($profile),
            'recommendedOffers' => array_slice($recommendedOffers, 0, 3),
            'compatibleOffersCount' => count(array_filter(
                $recommendedOffers,
                static fn (array $offer): bool => $offer['score'] >= 25,
            )),
            'savedOffersCount' => 0,
            'activeConversationsCount' => $activeConversationsCount,
            'unreadMessagesCount' => $unreadMessagesCount,
            'recruiterInteractions' => array_slice($interactions, 0, 4),
            'recruiterInteractionsCount' => count($interactions),
            'favoriteRecruitersCount' => count($favoriteProfiles),
            'visibilityScore' => $visibilityScore,
            'visibilityLabel' => $this->visibilityLabel($visibilityScore),
            'recommendations' => $this->buildProfileRecommendations($profile, $checklist),
            'profileMessage' => $this->profileFollowUpMessage($profile, $checklist, $completionPercent),
            'latestInteraction' => $conversationRows[0]['lastMessageAt'] ?? null,
            'profileViewsCount' => null,
            'contactsReceivedCount' => $activeConversationsCount,
        ];
    }

    /**
     * @param list<array{conversation: Conversation, recruiterUser: ?User, recruiterName: string, recruiterCompany: ?string, lastMessage: Message, lastMessagePreview: string, lastMessageAt: ?\DateTimeImmutable, unreadCount: int, status: string}> $conversationRows
     * @param list<object> $favoriteProfiles
     *
     * @return list<array{name: string, company: ?string, source: string}>
     */
    private function buildRecruiterInteractions(array $conversationRows, array $favoriteProfiles): array
    {
        $rows = [];
        $seen = [];

        foreach ($conversationRows as $row) {
            $recruiterUser = $row['recruiterUser'] ?? null;
            if (!$recruiterUser instanceof User) {
                continue;
            }

            $key = 'user-' . (string) $recruiterUser->getId();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $recruiterProfile = $recruiterUser->getRecruiterProfile();
            $rows[] = [
                'name' => $this->resolveRecruiterDisplayName($recruiterUser),
                'company' => $recruiterProfile?->getCompany()?->getName(),
                'source' => 'Conversation active',
            ];
        }

        foreach ($favoriteProfiles as $favoriteProfile) {
            if (!method_exists($favoriteProfile, 'getRecruiterProfile')) {
                continue;
            }

            $recruiterProfile = $favoriteProfile->getRecruiterProfile();
            if (null === $recruiterProfile) {
                continue;
            }

            $recruiterUser = $recruiterProfile->getUser();
            $key = $recruiterUser instanceof User ? 'user-' . (string) $recruiterUser->getId() : 'profile-' . (string) $recruiterProfile->getId();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $name = trim(sprintf('%s %s', (string) $recruiterProfile->getFirstName(), (string) $recruiterProfile->getLastName()));
            $rows[] = [
                'name' => '' !== $name ? $name : 'Recruteur',
                'company' => $recruiterProfile->getCompany()?->getName(),
                'source' => 'Profil suivi',
            ];
        }

        return $rows;
    }

    /**
     * @param list<JobOffer> $offers
     *
     * @return list<array{title: string, company: ?string, location: ?string, contract: string, score: int, matchedSkills: list<string>}>
     */
    private function buildCompatibleOfferRows(DeveloperProfile $profile, array $offers): array
    {
        $profileSkills = $this->buildSkillRows($profile);
        $desiredPositions = $this->buildDesiredPositionRows($profile);
        $rows = [];

        foreach ($offers as $offer) {
            $text = mb_strtolower(trim(sprintf('%s %s %s', (string) $offer->getTitle(), (string) $offer->getDescription(), (string) $offer->getLocation())));
            $matchedSkills = array_values(array_filter(
                $profileSkills,
                static fn (string $skill): bool => '' !== $skill && str_contains($text, mb_strtolower($skill)),
            ));
            $matchedPositions = array_values(array_filter(
                $desiredPositions,
                static fn (string $position): bool => '' !== $position && str_contains($text, mb_strtolower($position)),
            ));

            $score = min(45, count($matchedSkills) * 15) + min(25, count($matchedPositions) * 25);

            if (null !== $profile->getLocationType() && $offer->getLocationType()?->value === $profile->getLocationType()->value) {
                $score += 15;
            }

            $yearsExperience = $profile->getYearsExperience();
            $offerExperienceLevel = $offer->getExperienceLevel();
            if (null !== $yearsExperience && null !== $offerExperienceLevel) {
                $score += abs($yearsExperience - $offerExperienceLevel) <= 2 ? 15 : 5;
            } elseif (null !== $yearsExperience || null !== $offerExperienceLevel) {
                $score += 5;
            }

            if (0 === $score) {
                continue;
            }

            $rows[] = [
                'title' => (string) $offer->getTitle(),
                'company' => $offer->getRecruiterProfile()?->getCompany()?->getName(),
                'location' => $offer->getLocation(),
                'contract' => $this->contractLabel($offer),
                'score' => min(100, $score),
                'scoreLabel' => $this->offerScoreLabel(min(100, $score)),
                'matchedSkills' => array_slice($matchedSkills, 0, 3),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function buildSkillRows(?DeveloperProfile $profile): array
    {
        if (!$profile instanceof DeveloperProfile) {
            return [];
        }

        $skills = [];
        foreach ($profile->getProfileSkills() as $profileSkill) {
            $name = trim((string) $profileSkill->getSkill()?->getName());
            if ('' !== $name) {
                $skills[] = $name;
            }
        }

        return array_values(array_unique($skills));
    }

    /**
     * @return list<array{title: string, company: string}>
     */
    private function buildExperienceRows(?DeveloperProfile $profile): array
    {
        if (!$profile instanceof DeveloperProfile) {
            return [];
        }

        $experiences = $profile->getExperiences()->toArray();
        usort(
            $experiences,
            static fn ($left, $right): int => ($right->getStartDate()?->getTimestamp() ?? 0) <=> ($left->getStartDate()?->getTimestamp() ?? 0),
        );

        return array_values(array_map(
            static fn ($experience): array => [
                'title' => (string) $experience->getTitle(),
                'company' => (string) $experience->getCompanyName(),
            ],
            array_slice($experiences, 0, 3),
        ));
    }

    /**
     * @return list<array{degree: string, school: string}>
     */
    private function buildEducationRows(?DeveloperProfile $profile): array
    {
        if (!$profile instanceof DeveloperProfile) {
            return [];
        }

        $educationRows = $profile->getEducation()->toArray();
        usort(
            $educationRows,
            static fn ($left, $right): int => ($right->getStartDate()?->getTimestamp() ?? 0) <=> ($left->getStartDate()?->getTimestamp() ?? 0),
        );

        return array_values(array_map(
            static fn ($education): array => [
                'degree' => trim(sprintf('%s %s', (string) $education->getDegree(), (string) $education->getField())),
                'school' => (string) $education->getSchoolName(),
            ],
            array_slice($educationRows, 0, 3),
        ));
    }

    /**
     * @return list<string>
     */
    private function buildDesiredPositionRows(?DeveloperProfile $profile): array
    {
        if (!$profile instanceof DeveloperProfile) {
            return [];
        }

        $positions = [];
        foreach ($profile->getDesiredPositions() as $position) {
            $name = trim((string) $position->getName());
            if ('' !== $name) {
                $positions[] = $name;
            }
        }

        return array_values(array_unique($positions));
    }

    private function estimateProfileVisibilityScore(?DeveloperProfile $profile, int $completionPercent, int $interactionsCount, int $activeConversationsCount): int
    {
        if (!$profile instanceof DeveloperProfile) {
            return 0;
        }

        // Visibilité à 0% si profil privé
        if (!$profile->isPublic()) {
            return 0;
        }

        // Visibilité à 0% si toutes les étapes ne sont pas complétées (profil incomplet)
        // On considère que le portfolio doit être généré et toutes les étapes faites
        // (on peut adapter selon la logique métier exacte)
        if (
            null === $profile->getPortfolioGeneratedAt() ||
            empty(trim((string) $profile->getBio())) ||
            $profile->getExperiences()->count() === 0 ||
            $profile->getProfileSkills()->count() === 0 ||
            $profile->getEducation()->count() === 0 ||
            $profile->getDesiredPositions()->count() === 0
        ) {
            return 0;
        }

        $score = 0;

        // Taille de la description (bio)
        $bioLength = mb_strlen(trim((string) $profile->getBio()));
        if ($bioLength >= 500) {
            $score += 20;
        } elseif ($bioLength >= 250) {
            $score += 15;
        } elseif ($bioLength >= 100) {
            $score += 10;
        } elseif ($bioLength > 0) {
            $score += 5;
        }

        // Nombre d'expériences
        $expCount = $profile->getExperiences()->count();
        if ($expCount >= 4) {
            $score += 20;
        } elseif ($expCount >= 2) {
            $score += 15;
        } elseif ($expCount === 1) {
            $score += 8;
        }

        // Nombre de compétences
        $skillsCount = $profile->getProfileSkills()->count();
        if ($skillsCount >= 8) {
            $score += 20;
        } elseif ($skillsCount >= 4) {
            $score += 15;
        } elseif ($skillsCount >= 1) {
            $score += 8;
        }

        // Nombre de formations
        $eduCount = $profile->getEducation()->count();
        if ($eduCount >= 3) {
            $score += 10;
        } elseif ($eduCount >= 1) {
            $score += 5;
        }

        // Nombre de postes recherchés
        $positionsCount = $profile->getDesiredPositions()->count();
        if ($positionsCount >= 3) {
            $score += 10;
        } elseif ($positionsCount >= 1) {
            $score += 5;
        }

        // Présence de liens externes
        $hasExternalLink = '' !== trim((string) $profile->getLinkedinUrl())
            || '' !== trim((string) $profile->getGithubUrl())
            || '' !== trim((string) $profile->getPortfolioUrl());
        if ($hasExternalLink) {
            $score += 5;
        }

        // Interactions avec les recruteurs (messages, conversations)
        $score += min(10, ($interactionsCount + $activeConversationsCount) * 2);

        // Plafond à 100
        return min(100, $score);
    }

    private function visibilityLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Élevée',
            $score >= 55 => 'Bonne',
            $score >= 30 => 'À renforcer',
            default => 'Faible',
        };
    }

    /**
     * @param array<string, array{done: bool, route: string, label: string}> $checklist
     */
    private function profileFollowUpMessage(?DeveloperProfile $profile, array $checklist, int $completionPercent): string
    {
        if (!$profile instanceof DeveloperProfile) {
            return 'Créez votre profil pour activer le suivi des opportunités et des échanges recruteurs.';
        }

        if (0 === $profile->getExperiences()->count() && $checklist['step1']['done']) {
            return 'Votre profil est lancé. Ajoutez une expérience récente pour améliorer votre visibilité.';
        }

        if (0 === $profile->getProfileSkills()->count()) {
            return 'Ajoutez vos compétences techniques pour améliorer la précision du matching.';
        }

        if (!$checklist['step3']['done']) {
            return 'Ajoutez un lien GitHub, LinkedIn ou portfolio pour renforcer la lecture de votre profil.';
        }

        if ($completionPercent < 100) {
            return 'Votre profil est presque complet. Finalisez les étapes restantes pour faciliter le suivi recruteur.';
        }

        return 'Votre profil est complet. Gardez-le à jour pour maintenir la qualité des recommandations.';
    }

    private function offerScoreLabel(int $score): string
    {
        return match (true) {
            $score >= 70 => 'Compatibilité élevée',
            $score >= 40 => 'Compatibilité moyenne',
            default => 'Compatibilité à vérifier',
        };
    }

    /**
     * @param array<string, array{done: bool, route: string, label: string}> $checklist
     *
     * @return list<array{label: string, route: string, condition: string}>
     */
    private function buildProfileRecommendations(?DeveloperProfile $profile, array $checklist): array
    {
        if (!$profile instanceof DeveloperProfile) {
            return [[
                'label' => 'Créer le profil développeur pour débloquer le suivi candidat.',
                'route' => 'app_applicant_profile_create',
                'condition' => 'Proposé si aucun profil candidat n\'existe encore.',
            ]];
        }

        $recommendations = [];
        if (!$checklist['step1']['done']) {
            return [[
                'label' => 'Compléter les informations générales du profil.',
                'route' => 'app_applicant_profile_create',
                'condition' => 'Proposé si prénom, nom, titre, localisation, niveau, années d\'expérience ou présentation sont incomplets.',
            ]];
        }

        if (0 === $profile->getProfileSkills()->count()) {
            $recommendations[] = [
                'label' => 'Ajouter les compétences principales.',
                'route' => 'app_applicant_profile_step2',
                'condition' => 'Proposé si aucune compétence n\'est renseignée.',
            ];
        }
        if (0 === $profile->getExperiences()->count()) {
            $recommendations[] = [
                'label' => 'Renseigner au moins une expérience.',
                'route' => 'app_applicant_profile_step2',
                'condition' => 'Proposé si aucune expérience professionnelle n\'est ajoutée.',
            ];
        }
        if (0 === $profile->getEducation()->count()) {
            $recommendations[] = [
                'label' => 'Ajouter une formation ou certification.',
                'route' => 'app_applicant_profile_step2',
                'condition' => 'Proposé si aucune formation ou certification n\'est ajoutée.',
            ];
        }
        if (($checklist['step2']['done'] || $checklist['step3']['done'] || $checklist['step4']['done']) && !$checklist['step3']['done']) {
            $recommendations[] = [
                'label' => 'Ajouter un lien GitHub, LinkedIn ou portfolio.',
                'route' => 'app_applicant_profile_step3',
                'condition' => 'Proposé après le parcours et les skills si aucun lien externe n\'est renseigné.',
            ];
        }
        if (($checklist['step3']['done'] || $checklist['step4']['done']) && !$checklist['step4']['done']) {
            $recommendations[] = [
                'label' => 'Préciser les postes recherchés.',
                'route' => 'app_applicant_profile_step4',
                'condition' => 'Proposé après les liens si aucun poste recherché n\'est sélectionné.',
            ];
        }
        if (!in_array(false, array_column($checklist, 'done'), true) && null === $profile->getPortfolioGeneratedAt()) {
            $recommendations[] = [
                'label' => 'Générer le portfolio public.',
                'route' => 'app_applicant_portfolio_generate',
                'condition' => 'Proposé quand les 4 étapes du profil sont terminées mais que le portfolio n\'est pas encore généré.',
            ];
        }

        return array_slice($recommendations, 0, 4);
    }

    private function contractLabel(JobOffer $offer): string
    {
        return match ($offer->getContractType()?->value) {
            'full_time' => 'Temps plein',
            'part_time' => 'Temps partiel',
            'permanent' => 'CDI',
            'fixed_term' => 'CDD',
            'internship' => 'Stage',
            'apprenticeship' => 'Alternance',
            'freelance' => 'Freelance',
            'contract' => 'Contrat',
            default => 'Contrat non renseigné',
        };
    }

    /**
     * @return list<array{conversation: Conversation, recruiterUser: ?User, recruiterName: string, recruiterCompany: ?string, lastMessage: Message, lastMessagePreview: string, lastMessageAt: ?\DateTimeImmutable, unreadCount: int, status: string}>
     */
    private function buildApplicantConversationRows(ConversationRepository $conversationRepository, MessageRepository $messageRepository, User $user): array
    {
        $conversations = $conversationRepository->findActiveForApplicant($user);
        $conversationRows = [];
        foreach ($conversations as $conversation) {
            if (!$conversation instanceof Conversation) {
                continue;
            }

            $lastMessage = $messageRepository->findLastInConversation($conversation);
            if (!$lastMessage instanceof Message) {
                continue;
            }

            $conversationRows[] = [
                'conversation' => $conversation,
                'recruiterUser' => $conversation->getRecruiterUser(),
                'recruiterName' => $this->resolveRecruiterDisplayName($conversation->getRecruiterUser()),
                'recruiterCompany' => $conversation->getRecruiterUser()?->getRecruiterProfile()?->getCompany()?->getName(),
                'lastMessage' => $lastMessage,
                'lastMessagePreview' => mb_substr(trim(strip_tags((string) $lastMessage->getContent())), 0, 120),
                'lastMessageAt' => $lastMessage->getCreatedAt(),
                'unreadCount' => $messageRepository->countUnreadInConversationForUser($conversation, $user),
                'status' => 'Ouverte',
            ];
        }

        return $conversationRows;
    }

    private function resolveRecruiterDisplayName(?User $recruiterUser): string
    {
        if (!$recruiterUser instanceof User) {
            return 'Recruteur';
        }

        $recruiterProfile = $recruiterUser->getRecruiterProfile();
        if (null !== $recruiterProfile) {
            $fullName = trim(sprintf('%s %s', (string) $recruiterProfile->getFirstName(), (string) $recruiterProfile->getLastName()));
            if ('' !== $fullName) {
                return $fullName;
            }
        }

        return (string) ($recruiterUser->getEmail() ?? 'Recruteur');
    }

    private function redirectAfterProfileStep(Request $request, string $nextRoute, string $nextMessage, string $exitMessage, ?DeveloperProfile $profile = null, ?NotificationManager $notificationManager = null): Response
    {
        if ($profile instanceof DeveloperProfile && $notificationManager instanceof NotificationManager) {
            $notificationManager->notifyRecruitersFollowingProfileUpdated($profile);
        }

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
                '' !== trim((string) ($profile->getFirstName() ?? ''))
                && '' !== trim((string) ($profile->getLastName() ?? ''))
                && '' !== trim((string) ($profile->getHeadline() ?? ''))
                && '' !== trim((string) ($profile->getCity() ?? ''))
                && '' !== trim((string) ($profile->getCountry() ?? ''))
                && null !== $profile->getLocationType()
                && null !== $profile->getExperienceLevel()
                && null !== $profile->getYearsExperience()
                && '' !== trim((string) ($profile->getBio() ?? ''));

            $step2Done =
                $profile->getEducation()->count() > 0
                && $profile->getExperiences()->count() > 0
                && $profile->getProfileSkills()->count() > 0;

            $step3Done =
                '' !== trim((string) ($profile->getGithubUrl() ?? ''))
                && '' !== trim((string) ($profile->getLinkedinUrl() ?? ''))
                && '' !== trim((string) ($profile->getPortfolioUrl() ?? ''));

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
        $base = $firstName . $lastName;

        if ('' === $base) {
            $base = 'profil';
        }

        $slug = $base;
        $i = 2;

        while (null !== $developerProfileRepository->findOneBy(['slug' => $slug])) {
            $slug = $base . $i;
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

        $uploadDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
            throw new \RuntimeException('Le dossier de téléversement des avatars est introuvable.');
        }

        $this->removePreviousAvatar($profile, $uploadDirectory);

        $imageInfo = @getimagesize($avatarFile->getPathname());
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $slug = $profile->getSlug() ?: 'profil';
        $fileName = sprintf('%s-%s.%s', $slug, substr(bin2hex(random_bytes(4)), 0, 8), $extension);

        try {
            $avatarFile->move($uploadDirectory, $fileName);
        } catch (FileException $exception) {
            throw new \RuntimeException('Impossible d\'enregistrer la photo de profil.', 0, $exception);
        }

        $profile->setAvatarPath('uploads/avatars/' . $fileName);
    }

    private function removePreviousAvatar(DeveloperProfile $profile, string $uploadDirectory): void
    {
        $currentAvatarPath = $profile->getAvatarPath();
        if (null === $currentAvatarPath || !str_starts_with($currentAvatarPath, 'uploads/avatars/')) {
            return;
        }

        $currentFilePath = $this->getParameter('kernel.project_dir') . '/public/' . $currentAvatarPath;
        if (is_file($currentFilePath) && str_starts_with(dirname($currentFilePath), $uploadDirectory)) {
            @unlink($currentFilePath);
        }
    }
}
