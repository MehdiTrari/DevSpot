<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ContactMessage;
use App\Entity\DeveloperProfile;
use App\Entity\FavoriteProfile;
use App\Entity\JobOffer;
use App\Entity\Message;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\OfferStatus;
use App\Enum\UserStatus;
use App\Form\ChatReplyType;
use App\Form\ContactMessageType;
use App\Form\JobOfferType;
use App\Repository\ConversationRepository;
use App\Repository\DeveloperProfileRepository;
use App\Repository\FavoriteProfileRepository;
use App\Repository\MessageRepository;
use App\Service\ChatMercure;
use App\Service\LoggerService;
use App\Service\MatchingCandidateTokenService;
use App\Service\NotificationManager;
use App\Service\OfferMatchingService;
use App\Service\RecruiterConversationStarter;
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
    #[Route('/recruiter/dashboard', name: 'app_recruiter_dashboard')]
    #[IsGranted('ROLE_RECRUITER')]
    public function home(
        FavoriteProfileRepository $favoriteProfileRepository,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
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
        $interactionDashboard = $this->buildRecruiterInteractionDashboard(
            $recruiterProfile,
            $conversationRepository,
            $messageRepository,
            $favoriteProfileRepository,
        );

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
            'interactionDashboard' => $interactionDashboard,
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
        LoggerService $loggerService,
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
            $loggerService->log(
                LoggerService::OFFER_PUBLISHED,
                $recruiterProfile->getUser(),
                JobOffer::class,
                $offer->getId(),
                [
                    'status' => $offer->getStatus()->value,
                    'title' => $offer->getTitle(),
                ],
            );

            $this->addFlash('success', 'Offre créée avec succès !');

            return $this->redirectToRoute('app_recruiter_offer_detail', ['id' => $offer->getId()]);
        }

        return $this->render('recruiter/create_offer.html.twig', [
            'form' => $form,
            'pageTitle' => 'Créer une nouvelle offre',
            'pageIntro' => 'Prépare une annonce claire, structurée et exploitable rapidement pour ton matching et ta diffusion.',
            'submitLabel' => 'Créer l\'offre',
            'isEdit' => false,
        ]);
    }

    #[Route('/recruiter/offers/{id}/edit', name: 'app_recruiter_offer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function editOffer(
        JobOffer $offer,
        Request $request,
        EntityManagerInterface $entityManager,
        LoggerService $loggerService,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        $oldStatus = $offer->getStatus();
        $form = $this->createForm(JobOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $offer->setUpdatedAt(new \DateTimeImmutable());

            if (OfferStatus::PUBLISHED === $offer->getStatus() && $oldStatus !== $offer->getStatus()) {
                $loggerService->log(
                    LoggerService::OFFER_PUBLISHED,
                    $recruiterProfile->getUser(),
                    JobOffer::class,
                    $offer->getId(),
                    [
                        'old_status' => $oldStatus->value,
                        'new_status' => $offer->getStatus()->value,
                        'title' => $offer->getTitle(),
                    ],
                    flush: false,
                );
            }

            $entityManager->flush();
            $cache->deleteItem($this->buildMatchingCacheKey((int) $offer->getId()));
            $cache->deleteItem($this->buildMatchingSummaryCacheKey((int) $offer->getId()));

            $this->addFlash('success', 'Offre modifiée avec succès.');

            return $this->redirectToRoute('app_recruiter_offer_detail', ['id' => $offer->getId()]);
        }

        return $this->render('recruiter/create_offer.html.twig', [
            'form' => $form,
            'offer' => $offer,
            'pageTitle' => 'Modifier l\'offre',
            'pageIntro' => 'Mets à jour l\'annonce, sa date limite ou son statut. Pour réouvrir une offre fermée, choisis une date limite future puis repasse-la en publiée.',
            'submitLabel' => 'Enregistrer les modifications',
            'isEdit' => true,
        ]);
    }

    #[Route('/recruiter/offers/{id}/delete', name: 'app_recruiter_offer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function deleteOffer(
        JobOffer $offer,
        Request $request,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.app')] CacheItemPoolInterface $cache,
    ): Response {
        $recruiterProfile = $this->getRecruiterProfile();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        if (!$this->isCsrfTokenValid('offer_delete_' . $offer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_recruiter_offers');
        }

        $offerId = (int) $offer->getId();
        $entityManager->remove($offer);
        $entityManager->flush();
        $cache->deleteItem($this->buildMatchingCacheKey($offerId));
        $cache->deleteItem($this->buildMatchingSummaryCacheKey($offerId));

        $this->addFlash('success', 'Offre supprimée.');

        return $this->redirectToRoute('app_recruiter_offers');
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
        DeveloperProfileRepository $developerProfileRepository,
        ConversationRepository $conversationRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        MatchingCandidateTokenService $matchingCandidateTokenService,
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
            $firstPageMatches = $this->buildVisibleMatches(
                $firstPageMatches,
                $offer,
                $this->getRecruiterUser(),
                $developerProfileRepository,
                $conversationRepository,
                $favoriteProfileRepository,
                $csrfTokenManager,
                $matchingCandidateTokenService,
            );

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
            'matchingContactEndpoint' => $this->generateUrl('app_recruiter_offer_matching_contact', ['id' => $offer->getId()]),
            'matchingContactCsrfToken' => $csrfTokenManager->getToken('matching_contact_' . $offer->getId())->getValue(),
            'cachedMatching' => $cachedMatching,
        ]);
    }

    #[Route('/recruiter/offers/{id}/matching', name: 'app_recruiter_offer_matching', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function offerMatching(
        JobOffer $offer,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        ConversationRepository $conversationRepository,
        OfferMatchingService $offerMatchingService,
        FavoriteProfileRepository $favoriteProfileRepository,
        MatchingCandidateTokenService $matchingCandidateTokenService,
        LoggerService $loggerService,
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

            $normalizedMatches = array_map(
                fn (array $match, int $index): array => $this->normalizeMatchForCache($match, $index),
                $allMatches,
                array_keys($allMatches),
            );

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
            $loggerService->log(
                LoggerService::MATCHING_CALCULATED,
                $recruiterProfile->getUser(),
                JobOffer::class,
                $offer->getId(),
                [
                    'offer' => $cachedData['offer'],
                    'matchesCount' => $cachedData['matchesCount'],
                    'topMatchPercentage' => $cachedData['topMatchPercentage'],
                    'fairness' => $cachedData['fairness'],
                    'semantic' => $cachedData['semantic'],
                    'enriched' => $cachedData['enriched'],
                    'scores' => array_map(
                        static fn (array $match): array => [
                            'developerId' => $match['developerId'] ?? null,
                            'percentage' => $match['percentage'] ?? null,
                            'semanticPercentage' => $match['semanticPercentage'] ?? null,
                            'semanticEnrichedPercentage' => $match['semanticEnrichedPercentage'] ?? null,
                            'scoreBreakdown' => $match['scoreBreakdown'] ?? [],
                        ],
                        $normalizedMatches,
                    ),
                ],
            );
        } else {
            $cachedData = $item->get();

            $summaryItem = $cache->getItem($this->buildMatchingSummaryCacheKey((int) $offer->getId()));
            if (!$summaryItem->isHit()) {
                $summaryItem->set($this->extractMatchingSummary($cachedData));
                $summaryItem->expiresAfter(self::MATCHING_CACHE_TTL);
                $cache->save($summaryItem);
            }
        }

        $allMatches = $cachedData['matches'] ?? [];
        $totalMatches = count($allMatches);
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 10;
        $totalPages = max(1, (int) ceil($totalMatches / $perPage));
        $page = min($page, $totalPages);
        $visibleMatches = array_slice($allMatches, ($page - 1) * $perPage, $perPage);
        $visibleMatches = $this->buildVisibleMatches(
            $visibleMatches,
            $offer,
            $this->getRecruiterUser(),
            $developerProfileRepository,
            $conversationRepository,
            $favoriteProfileRepository,
            $csrfTokenManager,
            $matchingCandidateTokenService,
        );

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

    #[Route('/recruiter/offers/{id}/matching/contact', name: 'app_recruiter_offer_matching_contact', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function offerMatchingContact(
        JobOffer $offer,
        Request $request,
        DeveloperProfileRepository $developerProfileRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        MatchingCandidateTokenService $matchingCandidateTokenService,
        RecruiterConversationStarter $recruiterConversationStarter,
        CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'html_sanitizer.sanitizer.contact_message')]
        HtmlSanitizerInterface $contactMessageSanitizer,
    ): JsonResponse {
        $recruiterProfile = $this->getRecruiterProfile();
        $recruiterUser = $this->getRecruiterUser();
        if (!$recruiterProfile instanceof RecruiterProfile || $offer->getRecruiterProfile()?->getId() !== $recruiterProfile->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        $payload ??= $request->request->all();

        if (!$this->isCsrfTokenValid('matching_contact_' . $offer->getId(), (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Action refusée, merci de réessayer.',
            ], Response::HTTP_FORBIDDEN);
        }

        $tokenPayload = $matchingCandidateTokenService->parseToken((string) ($payload['contactToken'] ?? ''));
        if (
            null === $tokenPayload
            || $tokenPayload['offerId'] !== (int) $offer->getId()
            || $tokenPayload['recruiterUserId'] !== (int) $recruiterUser->getId()
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Ce candidat ne peut pas être contacté depuis ce matching.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $profile = $developerProfileRepository->find($tokenPayload['developerId']);
        if (
            !$profile instanceof DeveloperProfile
            || !$profile->isPublic()
            || null === $profile->getPortfolioGeneratedAt()
            || UserStatus::ACTIVE !== $profile->getUser()?->getStatus()
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Ce profil ne peut pas être contacté actuellement.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $contactMessage = new ContactMessage();
        $form = $this->createForm(ContactMessageType::class, $contactMessage, [
            'csrf_protection' => false,
        ]);
        $form->submit([
            'recruiterName' => $payload['recruiterName'] ?? null,
            'recruiterEmail' => $payload['recruiterEmail'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'message' => $payload['message'] ?? null,
        ]);

        if ($form->isSubmitted() && $form->isValid()) {
            $sanitizedRecruiterName = trim($contactMessageSanitizer->sanitize((string) $contactMessage->getRecruiterName()));
            $sanitizedSubject = trim($contactMessageSanitizer->sanitize((string) ($contactMessage->getSubject() ?? '')));
            $sanitizedMessage = trim($contactMessageSanitizer->sanitize((string) $contactMessage->getMessage()));

            $contactMessage->setRecruiterName($sanitizedRecruiterName);
            $contactMessage->setSubject($sanitizedSubject);
            $contactMessage->setMessage($sanitizedMessage);

            if ('' === $sanitizedRecruiterName) {
                $form->get('recruiterName')->addError(new FormError('Le nom contient trop de contenu HTML non autorisé.'));
            }

            if (mb_strlen($sanitizedMessage) < 10) {
                $form->get('message')->addError(new FormError('Le message contient trop de contenu HTML non autorisé.'));
            }
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Le formulaire contient des erreurs.',
                'errors' => $this->collectFormErrors($form),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $conversation = $recruiterConversationStarter->startConversation(
                $profile,
                $recruiterUser,
                (string) $contactMessage->getRecruiterName(),
                (string) $contactMessage->getRecruiterEmail(),
                (string) ($contactMessage->getSubject() ?? ''),
                (string) $contactMessage->getMessage(),
            );
        } catch (\DomainException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        $developerId = $profile->getId();
        $isFavorite = false;
        if (null !== $developerId) {
            $isFavorite = null !== $favoriteProfileRepository->findOneForRecruiterAndDeveloperProfile($recruiterProfile, $profile);
        }

        $favoritePayload = $this->buildFavoritePayload($developerId, $isFavorite, $csrfTokenManager);

        return new JsonResponse([
            'success' => true,
            'message' => 'Votre message a bien été envoyé au développeur.',
            'match' => array_filter([
                'revealed' => true,
                'fullName' => trim(sprintf('%s %s', (string) $profile->getFirstName(), (string) $profile->getLastName())),
                'headline' => (string) $profile->getHeadline(),
                'developerId' => $developerId,
                'profileUrl' => null !== $profile->getSlug() ? $this->generateUrl('app_public_profile_show', ['slug' => $profile->getSlug()]) : null,
                'conversationUrl' => $this->generateUrl('app_recruiter_message_show', ['conversationId' => $conversation->getId()]),
                'canContact' => false,
                ...$favoritePayload,
            ], static fn (mixed $value): bool => null !== $value),
        ]);
    }

    #[Route('/recruiter/offers/{id}/toggle-status', name: 'app_recruiter_offer_toggle_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function toggleOfferStatus(
        JobOffer $offer,
        Request $request,
        EntityManagerInterface $entityManager,
        LoggerService $loggerService,
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
            $oldStatus = $offer->getStatus();
            if (OfferStatus::PUBLISHED === $newStatus && null !== $offer->getApplicationDeadline() && $offer->getApplicationDeadline() < new \DateTimeImmutable('today')) {
                $this->addFlash('error', 'Impossible de republier une offre dont la date limite est dépassée. Mettez à jour sa date avant publication.');

                return $this->redirectToRoute('app_recruiter_offers');
            }

            $offer->setStatus($newStatus);
            if (OfferStatus::PUBLISHED === $newStatus && $oldStatus !== $newStatus) {
                $loggerService->log(
                    LoggerService::OFFER_PUBLISHED,
                    $recruiterProfile->getUser(),
                    JobOffer::class,
                    $offer->getId(),
                    [
                        'old_status' => $oldStatus?->value,
                        'new_status' => $newStatus->value,
                    ],
                    flush: false,
                );
            }
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
                'isActive' => OfferStatus::PUBLISHED === $status,
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

    /**
     * @return array{
     *     contactedProfilesCount: int,
     *     repliedProfilesCount: int,
     *     favoriteProfilesCount: int,
     *     activeConversationsCount: int,
     *     unansweredConversationsCount: int,
     *     linkedProfilesCount: int,
     *     responseRate: int,
     *     relaunchRows: list<array>,
     *     rows: list<array{
     *         profileName: string,
     *         headline: string,
     *         profileSlug: ?string,
     *         conversationId: ?int,
     *         linkedOfferTitle: ?string,
     *         exchangeStatus: string,
     *         lastSignal: string,
     *         lastActivityAt: ?\DateTimeImmutable,
     *         interestScore: int,
     *         interestLabel: string,
     *         isFavorite: bool,
     *         hasConversation: bool,
     *         hasReply: bool,
     *         isUnanswered: bool
     *     }>
     * }
     */
    private function buildRecruiterInteractionDashboard(
        RecruiterProfile $recruiterProfile,
        ConversationRepository $conversationRepository,
        MessageRepository $messageRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
    ): array {
        $recruiterUser = $recruiterProfile->getUser();
        if (!$recruiterUser instanceof User) {
            return [
                'contactedProfilesCount' => 0,
                'repliedProfilesCount' => 0,
                'favoriteProfilesCount' => 0,
                'activeConversationsCount' => 0,
                'unansweredConversationsCount' => 0,
                'linkedProfilesCount' => 0,
                'responseRate' => 0,
                'relaunchRows' => [],
                'rows' => [],
            ];
        }

        $favoriteProfiles = [];
        foreach ($favoriteProfileRepository->findForRecruiterProfile($recruiterProfile) as $favorite) {
            if (!$favorite instanceof FavoriteProfile || !$favorite->getDeveloperProfile() instanceof DeveloperProfile) {
                continue;
            }

            $developer = $favorite->getDeveloperProfile();
            $developerId = $developer->getId();
            if (null !== $developerId) {
                $favoriteProfiles[$developerId] = $developer;
            }
        }

        $offerTitles = [];
        foreach ($recruiterProfile->getJobOffers() as $offer) {
            if ($offer instanceof JobOffer && null !== $offer->getTitle() && '' !== trim($offer->getTitle())) {
                $offerTitles[] = trim($offer->getTitle());
            }
        }

        $rowsByProfileId = [];
        $contactedProfileIds = [];
        $repliedProfileIds = [];
        $unansweredConversationsCount = 0;
        $linkedProfileIds = [];

        foreach ($conversationRepository->findActiveForRecruiter($recruiterUser) as $conversation) {
            if (!$conversation instanceof Conversation) {
                continue;
            }

            $applicantUser = $conversation->getApplicantUser();
            $developer = $applicantUser?->getDeveloperProfile();
            if (!$developer instanceof DeveloperProfile) {
                continue;
            }

            $developerId = $developer->getId();
            if (null === $developerId) {
                continue;
            }

            $messages = $conversation->getMessages()->toArray();
            $recruiterMessagesCount = 0;
            $applicantMessagesCount = 0;
            $lastMessageAt = null;

            foreach ($messages as $message) {
                if (!$message instanceof Message) {
                    continue;
                }

                $senderId = $message->getSenderUser()?->getId();
                if ($senderId === $recruiterUser->getId()) {
                    ++$recruiterMessagesCount;
                } elseif ($senderId === $applicantUser?->getId()) {
                    ++$applicantMessagesCount;
                }

                $createdAt = $message->getCreatedAt();
                if ($createdAt instanceof \DateTimeImmutable && (null === $lastMessageAt || $createdAt > $lastMessageAt)) {
                    $lastMessageAt = $createdAt;
                }
            }

            $hasReply = $applicantMessagesCount > 0;
            $isUnanswered = $recruiterMessagesCount > 0 && !$hasReply;
            $linkedOfferTitle = $this->resolveLinkedOfferTitle((string) $conversation->getSubject(), $offerTitles);
            $lastMessage = $messageRepository->findLastInConversation($conversation);
            $lastSignal = $this->resolveRecruiterInteractionLastSignal($conversation, $lastMessage, $recruiterUser, $applicantUser, $hasReply, $isUnanswered);
            $exchangeStatus = $hasReply ? 'A répondu' : ($isUnanswered ? 'Sans réponse' : 'Conversation active');
            $isFavorite = isset($favoriteProfiles[$developerId]);
            $isRecent = ($conversation->getUpdatedAt() ?? $lastMessageAt) instanceof \DateTimeImmutable
                && ($conversation->getUpdatedAt() ?? $lastMessageAt) >= new \DateTimeImmutable('-14 days');
            $interestScore = $this->calculateRecruiterInterestScore(
                isFavorite: $isFavorite,
                hasConversation: true,
                hasReply: $hasReply,
                messagesCount: count($messages),
                hasLinkedOffer: null !== $linkedOfferTitle,
                isRecent: $isRecent,
            );

            $contactedProfileIds[$developerId] = true;
            if ($hasReply) {
                $repliedProfileIds[$developerId] = true;
            }
            if ($isUnanswered) {
                ++$unansweredConversationsCount;
            }
            if (null !== $linkedOfferTitle) {
                $linkedProfileIds[$developerId] = true;
            }

            $rowsByProfileId[$developerId] = [
                'profileName' => $this->formatDeveloperProfileName($developer),
                'headline' => (string) ($developer->getHeadline() ?: 'Profil développeur'),
                'profileSlug' => $developer->getSlug(),
                'conversationId' => $conversation->getId(),
                'linkedOfferTitle' => $linkedOfferTitle,
                'exchangeStatus' => $exchangeStatus,
                'lastSignal' => $lastSignal,
                'lastActivityAt' => $conversation->getUpdatedAt() ?? $lastMessageAt,
                'interestScore' => $interestScore,
                'interestLabel' => $this->resolveInterestLabel($interestScore),
                'isFavorite' => $isFavorite,
                'hasConversation' => true,
                'hasReply' => $hasReply,
                'isUnanswered' => $isUnanswered,
            ];
        }

        foreach ($favoriteProfiles as $developerId => $developer) {
            if (isset($rowsByProfileId[$developerId])) {
                continue;
            }

            $interestScore = $this->calculateRecruiterInterestScore(
                isFavorite: true,
                hasConversation: false,
                hasReply: false,
                messagesCount: 0,
                hasLinkedOffer: false,
                isRecent: false,
            );

            $rowsByProfileId[$developerId] = [
                'profileName' => $this->formatDeveloperProfileName($developer),
                'headline' => (string) ($developer->getHeadline() ?: 'Profil développeur'),
                'profileSlug' => $developer->getSlug(),
                'conversationId' => null,
                'linkedOfferTitle' => null,
                'exchangeStatus' => 'Favori sans contact',
                'lastSignal' => 'Ajouté aux favoris',
                'lastActivityAt' => null,
                'interestScore' => $interestScore,
                'interestLabel' => $this->resolveInterestLabel($interestScore),
                'isFavorite' => true,
                'hasConversation' => false,
                'hasReply' => false,
                'isUnanswered' => false,
            ];
        }

        $rows = array_values($rowsByProfileId);
        usort($rows, static fn (array $a, array $b): int => ($b['interestScore'] <=> $a['interestScore']) ?: (($b['lastActivityAt']?->getTimestamp() ?? 0) <=> ($a['lastActivityAt']?->getTimestamp() ?? 0)));
        $relaunchRows = $this->buildRecruiterRelaunchRows($rows);

        $contactedProfilesCount = count($contactedProfileIds);
        $repliedProfilesCount = count($repliedProfileIds);

        return [
            'contactedProfilesCount' => $contactedProfilesCount,
            'repliedProfilesCount' => $repliedProfilesCount,
            'favoriteProfilesCount' => count($favoriteProfiles),
            'activeConversationsCount' => count($conversationRepository->findActiveForRecruiter($recruiterUser)),
            'unansweredConversationsCount' => $unansweredConversationsCount,
            'linkedProfilesCount' => count($linkedProfileIds),
            'responseRate' => $contactedProfilesCount > 0 ? (int) round(($repliedProfilesCount / $contactedProfilesCount) * 100) : 0,
            'relaunchRows' => $relaunchRows,
            'rows' => array_slice($rows, 0, 8),
        ];
    }

    /**
     * @param list<array> $rows
     *
     * @return list<array>
     */
    private function buildRecruiterRelaunchRows(array $rows): array
    {
        $relaunchRows = [];
        $staleLimit = new \DateTimeImmutable('-7 days');

        foreach ($rows as $row) {
            $lastActivityAt = $row['lastActivityAt'] ?? null;
            $isStale = $lastActivityAt instanceof \DateTimeImmutable && $lastActivityAt <= $staleLimit;
            $isUnanswered = true === ($row['isUnanswered'] ?? false);
            $hasInterestingScoreWithoutRecentFollowUp = $isStale && (int) ($row['interestScore'] ?? 0) >= 45;

            if (!$isUnanswered && !$hasInterestingScoreWithoutRecentFollowUp) {
                continue;
            }

            $row['relaunchReason'] = $isUnanswered
                ? 'Contacté sans réponse'
                : 'Interaction ancienne avec intérêt à suivre';

            $relaunchRows[] = $row;
            if (count($relaunchRows) >= 4) {
                break;
            }
        }

        return $relaunchRows;
    }

    /**
     * @param list<string> $offerTitles
     */
    private function resolveLinkedOfferTitle(string $conversationSubject, array $offerTitles): ?string
    {
        $normalizedSubject = mb_strtolower($conversationSubject);
        foreach ($offerTitles as $offerTitle) {
            if ('' !== $offerTitle && str_contains($normalizedSubject, mb_strtolower($offerTitle))) {
                return $offerTitle;
            }
        }

        return null;
    }

    private function resolveRecruiterInteractionLastSignal(
        Conversation $conversation,
        ?Message $lastMessage,
        User $recruiterUser,
        ?User $applicantUser,
        bool $hasReply,
        bool $isUnanswered,
    ): string {
        if ($lastMessage instanceof Message) {
            $senderId = $lastMessage->getSenderUser()?->getId();
            if ($senderId === $applicantUser?->getId()) {
                return 'Réponse candidat';
            }

            if ($senderId === $recruiterUser->getId()) {
                return $hasReply ? 'Relance recruteur' : 'Premier message envoyé';
            }
        }

        if ($isUnanswered) {
            return 'En attente de réponse';
        }

        return (string) ($conversation->getSubject() ?: 'Conversation active');
    }

    private function calculateRecruiterInterestScore(
        bool $isFavorite,
        bool $hasConversation,
        bool $hasReply,
        int $messagesCount,
        bool $hasLinkedOffer,
        bool $isRecent,
    ): int {
        $score = 0;
        $score += $isFavorite ? 20 : 0;
        $score += $hasConversation ? 20 : 0;
        $score += $hasReply ? 25 : 0;
        $score += $messagesCount >= 4 ? 15 : 0;
        $score += $hasLinkedOffer ? 10 : 0;
        $score += $isRecent ? 10 : 0;

        return min(100, $score);
    }

    private function resolveInterestLabel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Élevé',
            $score >= 45 => 'À suivre',
            default => 'Initial',
        };
    }

    private function formatDeveloperProfileName(DeveloperProfile $developer): string
    {
        $fullName = trim(sprintf('%s %s', (string) $developer->getFirstName(), (string) $developer->getLastName()));

        return '' !== $fullName ? $fullName : 'Profil développeur';
    }

    private function buildMatchingCacheKey(int $offerId): string
    {
        return 'matching_offer_' . $offerId . $this->buildTestCacheSuffix();
    }

    private function buildMatchingSummaryCacheKey(int $offerId): string
    {
        return 'matching_offer_summary_' . $offerId . $this->buildTestCacheSuffix();
    }

    private function buildTestCacheSuffix(): string
    {
        $testToken = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null;

        if (!is_scalar($testToken) || '' === (string) $testToken) {
            return '';
        }

        return '_test_' . (string) $testToken;
    }

    /**
     * @param array<string, mixed> $cachedData
     *
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeMatchForCache(array $match, int $index): array
    {
        return [
            'candidateLabel' => sprintf('Candidat #%d', $index + 1),
            'developerId' => $match['developerId'] ?? null,
            'slug' => $match['slug'] ?? null,
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
    }

    /**
     * @param list<array<string, mixed>> $matches
     *
     * @return list<array<string, mixed>>
     */
    private function buildVisibleMatches(
        array $matches,
        JobOffer $offer,
        User $recruiterUser,
        DeveloperProfileRepository $developerProfileRepository,
        ConversationRepository $conversationRepository,
        FavoriteProfileRepository $favoriteProfileRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        MatchingCandidateTokenService $matchingCandidateTokenService,
    ): array {
        $recruiterProfile = $recruiterUser->getRecruiterProfile();
        $favoriteDeveloperIds = $recruiterProfile instanceof RecruiterProfile
            ? $favoriteProfileRepository->findFavoriteDeveloperProfileIdsForRecruiterProfile($recruiterProfile)
            : [];

        return array_map(function (array $match) use (
            $offer,
            $recruiterUser,
            $developerProfileRepository,
            $conversationRepository,
            $favoriteDeveloperIds,
            $csrfTokenManager,
            $matchingCandidateTokenService
        ): array {
            $developerId = isset($match['developerId']) && is_numeric($match['developerId']) ? (int) $match['developerId'] : null;
            $profile = null;
            $conversation = null;

            if (null !== $developerId) {
                $profile = $developerProfileRepository->find($developerId);
            }

            if ($profile instanceof DeveloperProfile && $profile->getUser() instanceof User) {
                $conversation = $conversationRepository->findOneBetweenUsers($profile->getUser(), $recruiterUser);
            }

            $payload = [
                'candidateLabel' => $match['candidateLabel'] ?? 'Candidat',
                'revealed' => $conversation instanceof Conversation,
                'canContact' => !$conversation instanceof Conversation && $profile instanceof DeveloperProfile,
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

            if (!$conversation instanceof Conversation) {
                if ($profile instanceof DeveloperProfile && null !== $developerId) {
                    $payload['contactToken'] = $matchingCandidateTokenService->generateToken(
                        (int) $offer->getId(),
                        $developerId,
                        (int) $recruiterUser->getId(),
                    );
                }

                return $payload;
            }

            $isFavorite = null !== $developerId && in_array($developerId, $favoriteDeveloperIds, true);

            return array_filter([
                ...$payload,
                'developerId' => $developerId,
                'fullName' => $match['fullName'] ?? 'Profil',
                'headline' => $match['headline'] ?? '',
                'profileUrl' => isset($match['slug']) && is_string($match['slug']) && '' !== $match['slug']
                    ? $this->generateUrl('app_public_profile_show', ['slug' => $match['slug']])
                    : null,
                'conversationUrl' => $this->generateUrl('app_recruiter_message_show', ['conversationId' => $conversation->getId()]),
                ...$this->buildFavoritePayload($developerId, $isFavorite, $csrfTokenManager),
            ], static fn (mixed $value): bool => null !== $value);
        }, $matches);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFavoritePayload(?int $developerId, bool $isFavorite, CsrfTokenManagerInterface $csrfTokenManager): array
    {
        if (null === $developerId) {
            return [];
        }

        return [
            'isFavorite' => $isFavorite,
            'favoriteAddUrl' => $this->generateUrl('app_recruiter_favorite_add', ['id' => $developerId]),
            'favoriteRemoveUrl' => $this->generateUrl('app_recruiter_favorite_remove', ['id' => $developerId]),
            'favoriteAddToken' => $csrfTokenManager->getToken('favorite_add_' . $developerId)->getValue(),
            'favoriteRemoveToken' => $csrfTokenManager->getToken('favorite_remove_' . $developerId)->getValue(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function collectFormErrors(\Symfony\Component\Form\FormInterface $form): array
    {
        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $name = $origin?->getName() ?? '_form';
            $errors[$name] ??= [];
            $errors[$name][] = $error->getMessage();
        }

        return $errors;
    }
}
