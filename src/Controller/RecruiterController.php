<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\DeveloperProfile;
use App\Entity\FavoriteProfile;
use App\Entity\JobOffer;
use App\Entity\Message;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\OfferStatus;
use App\Enum\UserStatus;
use App\Form\ChatReplyType;
use App\Form\JobOfferType;
use App\Repository\ConversationRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use App\Service\NotificationManager;
use App\Service\OfferMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RecruiterController extends AbstractController
{
    private const MATCHING_CACHE_TTL = 1800;

    #[Route('/recruiter', name: 'app_recruiter_home')]
    #[IsGranted('ROLE_RECRUITER')]
    public function home(
        FavoriteProfileRepository $favoriteProfileRepository,
        NotificationManager $notificationManager,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();

        if (!$recruiterProfile instanceof RecruiterProfile) {
            throw $this->createNotFoundException('Profil recruteur introuvable.');
        }

        if ($notificationManager->notifyRecruiterIncompleteProfileReminder($recruiterProfile->getUser())) {
            $entityManager->flush();
        }

        [$offerRows, $favoriteRows, $offersCount, $activeOffersCount, $closedOffersCount] = $this->buildRecruiterDashboardRows(
            $recruiterProfile,
            $favoriteProfileRepository,
            $cache,
        );

        $favoriteProfiles = $this->findCurrentRecruiterFavorites($favoriteProfileRepository);

        return $this->render('recruiter/dashboard.html.twig', [
            'matchingPreviewEndpoint' => $this->generateUrl('api_matching_preview'),
            'matchingPreviewPayload' => json_encode(
                $this->buildMatchingPreviewPayload(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            'offerRows' => $offerRows,
            'favoriteRows' => $favoriteRows,
            'offersCount' => $offersCount,
            'activeOffersCount' => $activeOffersCount,
            'closedOffersCount' => $closedOffersCount,
            'favoriteProfiles' => $favoriteProfiles,
            'favoriteProfilesCount' => count($favoriteProfiles),
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
        NotificationManager $notificationManager,
    ): Response {
        $redirectPath = $this->resolveFavoriteRedirectPath(
            $request,
            $this->generateUrl('app_public_profile_show', ['slug' => (string) $profile->getSlug()]),
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
        $notificationManager->notifyApplicantProfileFavorited($profile, $recruiterProfile);
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

    #[Route('/recruiter/offers/new', name: 'app_recruiter_offer_create', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function createOffer(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            throw $this->createNotFoundException('Profil recruteur introuvable.');
        }

        $offer = new JobOffer();
        $offer->setRecruiterProfile($recruiterProfile);
        $offer->setStatus(OfferStatus::PUBLISHED);

        $form = $this->createForm(JobOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($offer);
            $entityManager->flush();

            $this->addFlash('success', 'Offre créée avec succès !');

            return $this->redirectToRoute('app_recruiter_offer_detail', ['id' => $offer->getId()]);
        }

        return $this->render('recruiter/create_offer.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/recruiter/offers', name: 'app_recruiter_offers')]
    #[IsGranted('ROLE_RECRUITER')]
    public function offers(
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile) {
            throw $this->createNotFoundException('Profil recruteur introuvable.');
        }

        $offerRows = $this->buildRecruiterOfferRows($recruiterProfile);

        foreach ($offerRows as &$row) {
            $item = $cache->getItem($this->buildMatchingSummaryCacheKey((int) $row['id']));
            if ($item->isHit()) {
                $cached = $item->get();
                $row['topMatchPercentage'] = $cached['topMatchPercentage'] ?? null;
                $row['cachedAt'] = $cached['cachedAt'] ?? null;
                $row['matchesCount'] = $cached['matchesCount'] ?? 0;
            }
        }
        unset($row);

        return $this->render('recruiter/offers.html.twig', [
            'offerRows' => $offerRows,
        ]);
    }

    #[Route('/recruiter/offers/{id}', name: 'app_recruiter_offer_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function offerDetail(
        JobOffer $offer,
        FavoriteProfileRepository $favoriteProfileRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        $cachedMatching = null;
        $item = $cache->getItem($this->buildMatchingCacheKey((int) $offer->getId()));
        if ($item->isHit()) {
            $cached = $item->get();
            $allMatches = $cached['matches'] ?? [];
            $perPage = 10;
            $totalPages = max(1, (int) ceil(count($allMatches) / $perPage));
            $firstPageMatches = array_slice($allMatches, 0, $perPage);

            $favoriteDeveloperIds = $favoriteProfileRepository->findFavoriteDeveloperProfileIdsForRecruiterProfile($recruiterProfile);
            $firstPageMatches = array_map(function (array $match) use ($favoriteDeveloperIds, $csrfTokenManager): array {
                $developerId = $match['developerId'] ?? null;
                $isFavorite = null !== $developerId && in_array($developerId, $favoriteDeveloperIds, true);
                $match['isFavorite'] = $isFavorite;

                if (null !== $developerId) {
                    $match['favoriteAddUrl'] = $this->generateUrl('app_recruiter_favorite_add', ['id' => $developerId]);
                    $match['favoriteRemoveUrl'] = $this->generateUrl('app_recruiter_favorite_remove', ['id' => $developerId]);
                    $match['favoriteAddToken'] = $csrfTokenManager->getToken('favorite_add_' . $developerId)->getValue();
                    $match['favoriteRemoveToken'] = $csrfTokenManager->getToken('favorite_remove_' . $developerId)->getValue();
                }

                return $match;
            }, $firstPageMatches);

            $cachedMatching = json_encode([
                'offer' => $cached['offer'] ?? ['id' => $offer->getId(), 'title' => (string) $offer->getTitle()],
                'matchesCount' => count($allMatches),
                'displayedMatches' => count($firstPageMatches),
                'page' => 1,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
                'topMatchPercentage' => $cached['topMatchPercentage'] ?? null,
                'fairness' => $cached['fairness'] ?? null,
                'semantic' => $cached['semantic'] ?? null,
                'enriched' => $cached['enriched'] ?? null,
                'cachedAt' => $cached['cachedAt'] ?? null,
                'matches' => $firstPageMatches,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->render('recruiter/offer_detail.html.twig', [
            'offer' => $offer,
            'matchingEndpoint' => $this->generateUrl('app_recruiter_offer_matching', ['id' => $offer->getId()]),
            'cachedMatching' => $cachedMatching,
        ]);
    }

    #[Route('/recruiter/offers/{id}/matching', name: 'app_recruiter_offer_matching', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function offerMatching(
        JobOffer $offer,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        OfferMatchingService $offerMatchingService,
        FavoriteProfileRepository $favoriteProfileRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): JsonResponse {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        $cacheKey = $this->buildMatchingCacheKey((int) $offer->getId());
        $forceRefresh = $request->query->getBoolean('refresh', false);
        $item = $cache->getItem($cacheKey);

        if ($forceRefresh || !$item->isHit()) {
            $publicDevelopers = array_values(array_filter(
                $developerProfileRepository->findAllForMatching(),
                static fn (DeveloperProfile $developer): bool => true === $developer->isPublic() && null !== $developer->getPortfolioGeneratedAt(),
            ));

            $offerMatch = $offerMatchingService->buildSingleOfferMatch($offer, $publicDevelopers);
            $allMatches = $offerMatch['matches'] ?? [];

            $normalizedMatches = array_map(function (array $match): array {
                return [
                    'developerId' => $match['developerId'] ?? null,
                    'slug' => $match['slug'] ?? null,
                    'profileUrl' => isset($match['slug']) && is_string($match['slug']) && '' !== $match['slug']
                        ? $this->generateUrl('app_public_profile_show', ['slug' => $match['slug']])
                        : null,
                    'fullName' => $match['fullName'] ?? 'Profil',
                    'headline' => $match['headline'] ?? '',
                    'yearsExperience' => $match['yearsExperience'] ?? 0,
                    'percentage' => $match['percentage'] ?? null,
                    'semanticPercentage' => $match['semanticPercentage'] ?? null,
                    'semanticEnrichedPercentage' => $match['semanticEnrichedPercentage'] ?? null,
                    'matchedHardSkills' => $match['matchedHardSkills'] ?? [],
                    'matchedSoftSkills' => $match['matchedSoftSkills'] ?? [],
                    'inferredSoftSkills' => $match['inferredSoftSkills'] ?? [],
                    'inferredTransferableSkills' => $match['inferredTransferableSkills'] ?? [],
                    'inferredTechnicalSkills' => $match['inferredTechnicalSkills'] ?? [],
                    'scoreBreakdown' => $match['scoreBreakdown'] ?? [],
                ];
            }, $allMatches);

            $cachedData = [
                'offer' => $offerMatch['offer'] ?? [
                    'id' => $offer->getId(),
                    'title' => (string) $offer->getTitle(),
                ],
                'topMatchPercentage' => $normalizedMatches[0]['semanticEnrichedPercentage'] ?? null,
                'fairness' => $offerMatch['fairness'] ?? null,
                'semantic' => $offerMatch['semantic'] ?? null,
                'enriched' => $offerMatch['enriched'] ?? null,
                'matches' => $normalizedMatches,
                'matchesCount' => count($normalizedMatches),
                'cachedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
            ];

            $item->set($cachedData);
            $item->expiresAfter(self::MATCHING_CACHE_TTL);
            $cache->save($item);

            $summaryItem = $cache->getItem($this->buildMatchingSummaryCacheKey((int) $offer->getId()));
            $summaryItem->set($this->extractMatchingSummary($cachedData));
            $summaryItem->expiresAfter(self::MATCHING_CACHE_TTL);
            $cache->save($summaryItem);
        } else {
            $cachedData = $item->get();

            $summaryItem = $cache->getItem($this->buildMatchingSummaryCacheKey((int) $offer->getId()));
            if (!$summaryItem->isHit()) {
                $summaryItem->set($this->extractMatchingSummary($cachedData));
                $summaryItem->expiresAfter(self::MATCHING_CACHE_TTL);
                $cache->save($summaryItem);
            }
        }

        $favoriteDeveloperIds = $favoriteProfileRepository->findFavoriteDeveloperProfileIdsForRecruiterProfile($recruiterProfile);
        $allMatches = $cachedData['matches'] ?? [];
        $totalMatches = count($allMatches);
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 10;
        $totalPages = max(1, (int) ceil($totalMatches / $perPage));
        $page = min($page, $totalPages);
        $visibleMatches = array_slice($allMatches, ($page - 1) * $perPage, $perPage);
        $visibleMatches = array_map(function (array $match) use ($favoriteDeveloperIds, $csrfTokenManager): array {
            $developerId = $match['developerId'] ?? null;
            $isFavorite = null !== $developerId && in_array($developerId, $favoriteDeveloperIds, true);
            $match['isFavorite'] = $isFavorite;

            if (null !== $developerId) {
                $match['favoriteAddUrl'] = $this->generateUrl('app_recruiter_favorite_add', ['id' => $developerId]);
                $match['favoriteRemoveUrl'] = $this->generateUrl('app_recruiter_favorite_remove', ['id' => $developerId]);
                $match['favoriteAddToken'] = $csrfTokenManager->getToken('favorite_add_' . $developerId)->getValue();
                $match['favoriteRemoveToken'] = $csrfTokenManager->getToken('favorite_remove_' . $developerId)->getValue();
            }

            return $match;
        }, $visibleMatches);

        return $this->json([
            'offer' => $cachedData['offer'],
            'matchesCount' => $totalMatches,
            'displayedMatches' => count($visibleMatches),
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'topMatchPercentage' => $cachedData['topMatchPercentage'],
            'fairness' => $cachedData['fairness'],
            'semantic' => $cachedData['semantic'],
            'enriched' => $cachedData['enriched'],
            'cachedAt' => $cachedData['cachedAt'],
            'matches' => $visibleMatches,
        ]);
    }

    #[Route('/recruiter/offers/{id}/toggle-status', name: 'app_recruiter_offer_toggle_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function toggleOfferStatus(
        JobOffer $offer,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        if (!$this->isCsrfTokenValid('offer_toggle_' . $offer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_recruiter_offers');
        }

        $newStatus = match ((string) $request->request->get('action')) {
            'publish' => OfferStatus::PUBLISHED,
            'close' => OfferStatus::CLOSED,
            'draft' => OfferStatus::DRAFT,
            default => null,
        };

        if ($newStatus instanceof OfferStatus) {
            if (OfferStatus::PUBLISHED === $newStatus && null !== $offer->getApplicationDeadline() && $offer->getApplicationDeadline() < new \DateTimeImmutable('today')) {
                $this->addFlash('error', 'Impossible de republier une offre dont la date limite est dépassée. Mettez à jour sa date avant publication.');

                return $this->redirectToRoute('app_recruiter_offers');
            }

            $offer->setStatus($newStatus);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_recruiter_offers');
    }

    #[Route('/recruiter/messages', name: 'app_recruiter_messages')]
    #[IsGranted('ROLE_RECRUITER')]
    public function messages(
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        ChatMercure $chatMercure,
    ): Response {
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

    #[Route('/recruiter/messages/{conversationId}/read', name: 'app_recruiter_message_mark_read', requirements: ['conversationId' => '\d+'], methods: ['POST'])]
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

    /**
    * @return list<array{id: int, title: string, contract: string, status: string, statusValue: string, isActive: bool, location: ?string, updatedAt: mixed, applicationDeadline: mixed, detailUrl: string, topMatchPercentage: ?float, cachedAt: ?string, matchesCount: int}>
     */
    private function buildRecruiterOfferRows(RecruiterProfile $recruiterProfile): array
    {
        $rows = [];

        foreach ($recruiterProfile->getJobOffers() as $offer) {
            if (!$offer instanceof JobOffer) {
                continue;
            }

            $contractType = $offer->getContractType();
            $contractLabel = null !== $contractType ? match ($contractType->value) {
                'full_time' => 'Temps plein',
                'part_time' => 'Temps partiel',
                'permanent' => 'CDI',
                'fixed_term' => 'CDD',
                'internship' => 'Stage',
                'apprenticeship' => 'Alternance',
                'freelance' => 'Freelance',
                'contract' => 'Contrat',
                default => $contractType->value,
            } : 'Non défini';

            $status = $offer->getStatus();
            $statusLabel = match ($status) {
                OfferStatus::PUBLISHED => 'Publiée',
                OfferStatus::CLOSED => 'Fermée',
                OfferStatus::DRAFT => 'Brouillon',
            };

            $rows[] = [
                'id' => $offer->getId(),
                'title' => (string) $offer->getTitle(),
                'contract' => $contractLabel,
                'status' => $statusLabel,
                'statusValue' => $status->value,
                'isActive' => $status === OfferStatus::PUBLISHED,
                'location' => $offer->getLocation(),
                'updatedAt' => $offer->getUpdatedAt(),
                'applicationDeadline' => $offer->getApplicationDeadline(),
                'detailUrl' => $this->generateUrl('app_recruiter_offer_detail', ['id' => $offer->getId()]),
                'topMatchPercentage' => null,
                'cachedAt' => null,
                'matchesCount' => 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: list<array>, 1: list<array>, 2: int, 3: int, 4: int}
     */
    private function buildRecruiterDashboardRows(
        RecruiterProfile $recruiterProfile,
        FavoriteProfileRepository $favoriteProfileRepository,
        CacheItemPoolInterface $cache,
    ): array {
        $allOfferRows = $this->buildRecruiterOfferRows($recruiterProfile);
        $offersCount = count($allOfferRows);
        $activeOffersCount = count(array_filter($allOfferRows, static fn (array $row): bool => ($row['statusValue'] ?? '') === OfferStatus::PUBLISHED->value));
        $closedOffersCount = count(array_filter($allOfferRows, static fn (array $row): bool => ($row['statusValue'] ?? '') === OfferStatus::CLOSED->value));

        $offerRows = [];
        foreach (array_slice($allOfferRows, 0, 6) as $baseRow) {
            $row = $baseRow;
            $row['applicants'] = 0;
            $row['topMatchName'] = null;

            $item = $cache->getItem($this->buildMatchingSummaryCacheKey((int) $row['id']));
            if ($item->isHit()) {
                $cached = $item->get();
                $matchesCount = (int) ($cached['matchesCount'] ?? 0);
                $row['topMatchPercentage'] = $cached['topMatchPercentage'] ?? null;
                $row['cachedAt'] = $cached['cachedAt'] ?? null;
                $row['matchesCount'] = $matchesCount;
                $row['applicants'] = $matchesCount;
                $row['topMatchName'] = $cached['topMatchName'] ?? null;
            }

            $offerRows[] = $row;
        }

        $favoriteRows = [];
        foreach ($favoriteProfileRepository->findForRecruiterProfile($recruiterProfile) as $favorite) {
            if (!$favorite instanceof FavoriteProfile) {
                continue;
            }

            $developer = $favorite->getDeveloperProfile();
            if (!$developer instanceof DeveloperProfile) {
                continue;
            }

            $locationParts = array_values(array_filter([
                $developer->getCity(),
                $developer->getCountry(),
            ], static fn (?string $value): bool => null !== $value && '' !== trim($value)));

            $availability = match ($developer->getLocationType()?->value) {
                'remote' => 'Remote',
                'hybrid' => 'Hybride',
                'onsite' => 'Sur site',
                default => 'Disponibilité non renseignée',
            };

            $favoriteRows[] = [
                'fullName' => trim(sprintf('%s %s', (string) $developer->getFirstName(), (string) $developer->getLastName())),
                'headline' => (string) $developer->getHeadline(),
                'slug' => $developer->getSlug(),
                'location' => [] !== $locationParts ? implode(', ', $locationParts) : null,
                'availability' => $availability,
                'yearsExperience' => (int) ($developer->getYearsExperience() ?? 0),
                'bestMatchPercentage' => null,
                'bestOfferTitle' => null,
            ];
        }

        return [$offerRows, $favoriteRows, $offersCount, $activeOffersCount, $closedOffersCount];
    }

    private function buildMatchingCacheKey(int $offerId): string
    {
        return 'matching_offer_' . $offerId;
    }

    private function buildMatchingSummaryCacheKey(int $offerId): string
    {
        return 'matching_offer_summary_' . $offerId;
    }

    /**
     * @param array<string, mixed> $cachedData
     * @return array{topMatchPercentage: ?float, topMatchName: ?string, matchesCount: int, cachedAt: ?string}
     */
    private function extractMatchingSummary(array $cachedData): array
    {
        $firstMatch = $cachedData['matches'][0] ?? null;

        return [
            'topMatchPercentage' => isset($cachedData['topMatchPercentage']) && (is_float($cachedData['topMatchPercentage']) || is_int($cachedData['topMatchPercentage']))
                ? (float) $cachedData['topMatchPercentage']
                : null,
            'topMatchName' => is_array($firstMatch) && isset($firstMatch['fullName']) && is_string($firstMatch['fullName'])
                ? $firstMatch['fullName']
                : null,
            'matchesCount' => (int) ($cachedData['matchesCount'] ?? 0),
            'cachedAt' => isset($cachedData['cachedAt']) && is_string($cachedData['cachedAt']) ? $cachedData['cachedAt'] : null,
        ];
    }

    private function buildMatchingPreviewPayload(): array
    {
        return [
            'offers' => [],
            'developers' => [],
        ];
    }
}
