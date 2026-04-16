<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\FavoriteProfile;
use App\Entity\Message;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Form\ChatReplyType;
use App\Repository\ConversationRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RecruiterController extends AbstractController
{
    #[Route('/recruiter', name: 'app_recruiter_home')]
    #[IsGranted('ROLE_RECRUITER')]
    public function home(FavoriteProfileRepository $favoriteProfileRepository): Response
    {
        $favorites = $this->findCurrentRecruiterFavorites($favoriteProfileRepository);

        return $this->render('recruiter/dashboard.html.twig', [
            'favoriteProfiles' => $favorites,
            'favoriteProfilesCount' => count($favorites),
        ]);
    }

    #[Route('/recruiter/favorites', name: 'app_recruiter_favorites', methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function favorites(Request $request, FavoriteProfileRepository $favoriteProfileRepository): Response
    {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            return $this->render('recruiter/favorites.html.twig', [
                'favoriteProfiles' => [],
                'favoriteProfilesCount' => 0,
                'currentPage' => 1,
                'totalPages' => 1,
            ]);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 9;
        $totalFavorites = $favoriteProfileRepository->countForRecruiterProfile($recruiterProfile);
        $totalPages = max(1, (int) ceil($totalFavorites / $perPage));
        $currentPage = min($page, $totalPages);
        $favorites = $favoriteProfileRepository->findForRecruiterProfilePaginated($recruiterProfile, $currentPage, $perPage);

        return $this->render('recruiter/favorites.html.twig', [
            'favoriteProfiles' => $favorites,
            'favoriteProfilesCount' => $totalFavorites,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/recruiter/favorites/{id}/add', name: 'app_recruiter_favorite_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function addFavorite(
        DeveloperProfile $profile,
        Request $request,
        FavoriteProfileRepository $favoriteProfileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $redirectPath = $this->resolveFavoriteRedirectPath(
            $request,
            $this->generateUrl('app_public_profile_show', ['slug' => (string) $profile->getSlug()])
        );

        if (!$this->isCsrfTokenValid('favorite_add_' . $profile->getId(), (string) $request->request->get('_token'))) {
            return $this->favoriteFailureResponse($request, $redirectPath, 'Action refusée, merci de réessayer.', 'error', Response::HTTP_FORBIDDEN);
        }

        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            return $this->favoriteFailureResponse($request, $redirectPath, 'Votre profil recruteur est introuvable.', 'error', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isFavoritableProfile($profile)) {
            return $this->favoriteFailureResponse($request, $this->generateUrl('app_home'), 'Ce profil ne peut pas être ajouté aux favoris.', 'error', Response::HTTP_BAD_REQUEST);
        }

        $existingFavorite = $favoriteProfileRepository->findOneForRecruiterAndDeveloperProfile($recruiterProfile, $profile);
        if ($existingFavorite instanceof FavoriteProfile) {
            return $this->favoriteSuccessResponse($request, $redirectPath, $favoriteProfileRepository, $recruiterProfile, $profile, true, 'Ce profil est déjà dans vos favoris.', 'info');
        }

        $favoriteProfile = new FavoriteProfile();
        $favoriteProfile->setRecruiterProfile($recruiterProfile);
        $favoriteProfile->setDeveloperProfile($profile);

        $entityManager->persist($favoriteProfile);
        $entityManager->flush();

        return $this->favoriteSuccessResponse($request, $redirectPath, $favoriteProfileRepository, $recruiterProfile, $profile, true, 'Profil ajouté aux favoris.');
    }

    #[Route('/recruiter/favorites/{id}/remove', name: 'app_recruiter_favorite_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function removeFavorite(
        DeveloperProfile $profile,
        Request $request,
        FavoriteProfileRepository $favoriteProfileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $redirectPath = $this->resolveFavoriteRedirectPath($request, $this->generateUrl('app_recruiter_home'));

        if (!$this->isCsrfTokenValid('favorite_remove_' . $profile->getId(), (string) $request->request->get('_token'))) {
            return $this->favoriteFailureResponse($request, $redirectPath, 'Action refusée, merci de réessayer.', 'error', Response::HTTP_FORBIDDEN);
        }

        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            return $this->favoriteFailureResponse($request, $redirectPath, 'Votre profil recruteur est introuvable.', 'error', Response::HTTP_NOT_FOUND);
        }

        $favoriteProfile = $favoriteProfileRepository->findOneForRecruiterAndDeveloperProfile($recruiterProfile, $profile);
        if (!$favoriteProfile instanceof FavoriteProfile) {
            return $this->favoriteSuccessResponse($request, $redirectPath, $favoriteProfileRepository, $recruiterProfile, $profile, false, 'Ce profil n\'est pas dans vos favoris.', 'info');
        }

        $entityManager->remove($favoriteProfile);
        $entityManager->flush();

        return $this->favoriteSuccessResponse($request, $redirectPath, $favoriteProfileRepository, $recruiterProfile, $profile, false, 'Profil retiré des favoris.');
    }

    #[Route('/recruiter/messages', name: 'app_recruiter_messages')]
    #[IsGranted('ROLE_RECRUITER')]
    public function messages(
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        ChatMercure $chatMercure,
    ): Response
    {
        $recruiterUser = $this->getRecruiterUser();
        $conversationRows = $this->buildRecruiterConversationRows($conversationRepository, $messageRepository, $recruiterUser);

        return $this->render('recruiter/messages.html.twig', [
            'conversations' => $conversationRows,
            'mercureTopics' => $chatMercure->getTopicsForUser($recruiterUser),
            'mercureNeedsCredentials' => $chatMercure->requiresCredentials(),
            'selectedConversation' => null,
            'selectedMessages' => [],
            'selectedProfile' => null,
            'replyForm' => null,
        ]);
    }

    #[Route('/recruiter/messages/{conversationId}', name: 'app_recruiter_message_show', requirements: ['conversationId' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_RECRUITER')]
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
        $recruiterUser = $this->getRecruiterUser();

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getRecruiterUser()?->getId() !== $recruiterUser->getId()) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        $messageRepository->markConversationAsReadForUser($conversation, $recruiterUser);

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
                    ->setSenderUser($recruiterUser)
                    ->setIsRead(false)
                    ->setCreatedAt(new \DateTimeImmutable());

                $conversation->setUpdatedAt(new \DateTimeImmutable());

                $entityManager->persist($replyMessage);
                $notificationManager->notifyConversationNewMessage($replyMessage);
                $entityManager->flush();
                $chatMercure->publishMessage($replyMessage);

                if ($request->isXmlHttpRequest()) {
                    $html = $this->renderView('recruiter/_chat_message.html.twig', [
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

                return $this->redirectToRoute('app_recruiter_message_show', ['conversationId' => $conversationId]);
            }
        }

        $conversationRows = $this->buildRecruiterConversationRows($conversationRepository, $messageRepository, $recruiterUser);
        $conversationMessages = $messageRepository->findByConversationOrdered($conversation);
        $profile = $conversation->getApplicantUser()?->getDeveloperProfile();
        if (!$profile instanceof DeveloperProfile) {
            throw $this->createNotFoundException('Profil postulant introuvable.');
        }

        return $this->render('recruiter/messages.html.twig', [
            'conversations' => $conversationRows,
            'mercureTopics' => $chatMercure->getTopicsForUser($recruiterUser, $conversation),
            'mercureNeedsCredentials' => $chatMercure->requiresCredentials(),
            'selectedConversation' => $conversation,
            'selectedMessages' => $conversationMessages,
            'selectedProfile' => $profile,
            'replyForm' => $replyForm,
        ]);
    }

    #[Route('/recruiter/messages/{conversationId}/poll', name: 'app_recruiter_message_poll', requirements: ['conversationId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function pollConversation(
        int $conversationId,
        Request $request,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
    ): JsonResponse {
        $recruiterUser = $this->getRecruiterUser();

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getRecruiterUser()?->getId() !== $recruiterUser->getId()) {
            return new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        $sinceId = max(0, (int) $request->query->get('sinceId', 0));
        $newMessages = $messageRepository->findByConversationAfterIdOrdered($conversation, $sinceId);

        $messageRepository->markConversationAsReadForUser($conversation, $recruiterUser);

        $html = '';
        $lastId = $sinceId;
        foreach ($newMessages as $message) {
            $mine = $message->getSenderUser()?->getId() === $recruiterUser->getId();
            $html .= $this->renderView('recruiter/_chat_message.html.twig', [
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

    #[Route('/recruiter/messages/{conversationId}/read', name: 'app_recruiter_message_mark_read', requirements: ['conversationId' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function markConversationRead(
        int $conversationId,
        Request $request,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        ChatMercure $chatMercure,
    ): JsonResponse {
        $recruiterUser = $this->getRecruiterUser();

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation instanceof Conversation || $conversation->getRecruiterUser()?->getId() !== $recruiterUser->getId()) {
            return new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('chat_read_' . $conversationId, (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $updatedCount = $messageRepository->markConversationAsReadForUser($conversation, $recruiterUser);
        if ($updatedCount > 0) {
            $chatMercure->publishConversationReadState($recruiterUser, $conversation);
        }

        return new JsonResponse(['ok' => true, 'updated' => $updatedCount]);
    }

    private function getRecruiterUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function getRecruiterProfile(): ?RecruiterProfile
    {
        return $this->getRecruiterUser()->getRecruiterProfile();
    }

    private function resolveRecruiterDisplayName(User $recruiterUser): string
    {
        $recruiterProfile = $recruiterUser->getRecruiterProfile();
        if (null !== $recruiterProfile) {
            $fullName = trim(sprintf('%s %s', (string) $recruiterProfile->getFirstName(), (string) $recruiterProfile->getLastName()));
            if ('' !== $fullName) {
                return $fullName;
            }
        }

        return (string) ($recruiterUser->getEmail() ?? 'Recruteur');
    }

    /**
     * @return list<array{conversation: Conversation, developerProfile: ?DeveloperProfile, lastMessage: Message, unreadCount: int}>
     */
    private function buildRecruiterConversationRows(ConversationRepository $conversationRepository, MessageRepository $messageRepository, User $recruiterUser): array
    {
        $conversations = $conversationRepository->findActiveForRecruiter($recruiterUser);
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
                'developerProfile' => $conversation->getApplicantUser()?->getDeveloperProfile(),
                'lastMessage' => $lastMessage,
                'unreadCount' => $messageRepository->countUnreadInConversationForUser($conversation, $recruiterUser),
            ];
        }

        return $conversationRows;
    }

    private function isFavoritableProfile(DeveloperProfile $profile): bool
    {
        return $profile->isPublic()
            && null !== $profile->getPortfolioGeneratedAt()
            && UserStatus::ACTIVE === $profile->getUser()?->getStatus();
    }

    private function resolveFavoriteRedirectPath(Request $request, string $fallbackPath): string
    {
        $redirectPath = trim((string) $request->request->get('_redirect', ''));

        if (str_starts_with($redirectPath, '/')) {
            return $redirectPath;
        }

        return $fallbackPath;
    }

    private function favoriteFailureResponse(Request $request, string $redirectPath, string $message, string $type = 'error', int $statusCode = Response::HTTP_BAD_REQUEST): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => false,
                'message' => $message,
                'type' => $type,
            ], $statusCode);
        }

        $this->addFlash($type, $message);

        return $this->redirect($redirectPath);
    }

    private function favoriteSuccessResponse(
        Request $request,
        string $redirectPath,
        FavoriteProfileRepository $favoriteProfileRepository,
        RecruiterProfile $recruiterProfile,
        DeveloperProfile $profile,
        bool $isFavorite,
        string $message,
        string $type = 'success',
    ): Response {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'type' => $type,
                'isFavorite' => $isFavorite,
                'profileId' => $profile->getId(),
                'favoriteCount' => count($favoriteProfileRepository->findForRecruiterProfile($recruiterProfile)),
            ]);
        }

        $this->addFlash($type, $message);

        return $this->redirect($redirectPath);
    }

    /**
     * @return list<FavoriteProfile>
     */
    private function findCurrentRecruiterFavorites(FavoriteProfileRepository $favoriteProfileRepository): array
    {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            return [];
        }

        return $favoriteProfileRepository->findForRecruiterProfile($recruiterProfile);
    }
}
