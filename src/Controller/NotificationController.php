<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    private const PER_PAGE = 10;
    private const FILTER_ALL = 'all';
    private const FILTER_UNREAD = 'unread';
    private const FILTER_READ = 'read';

    #[Route('', name: 'app_notifications_index', methods: ['GET'])]
    public function index(Request $request, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $this->normalizeFilter($request->query->get('filter'));
        $requestedPage = max(1, $request->query->getInt('page', 1));

        $isRead = $this->resolveReadFilter($filter);

        $total = $notificationRepository->countForUser($user, $isRead);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $notifications = $notificationRepository->findForUserOrderedPaginated($user, $isRead, $currentPage, self::PER_PAGE);
        $unreadCount = $notificationRepository->countUnreadForUser($user);

        if ($request->isXmlHttpRequest()) {
            return $this->render('notification/_dynamic_content.html.twig', [
                'notifications' => $notifications,
                'currentFilter' => $filter,
                'unreadCount' => $unreadCount,
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
            ]);
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'currentFilter' => $filter,
            'unreadCount' => $unreadCount,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/{id}/read', name: 'app_notifications_mark_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request, EntityManagerInterface $entityManager, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $this->normalizeFilter($request->query->get('filter'));
        $page = max(1, $request->query->getInt('page', 1));

        if ($notification->getUser()?->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Accès interdit à cette notification.');
        }

        if (!$this->isCsrfTokenValid('notification_read_'.$notification->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new Response('CSRF invalid', Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_notifications_index');
        }

        $notification->markAsRead();
        $entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->renderNotificationsPartial($filter, $user, $request, $notificationRepository);
        }

        $this->addFlash('success', 'Notification marquée comme lue.');

        return $this->redirectToRoute('app_notifications_index', [
            'filter' => $filter,
            'page' => $page,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_notifications_delete', methods: ['POST'])]
    public function delete(Notification $notification, Request $request, EntityManagerInterface $entityManager, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $this->normalizeFilter($request->query->get('filter'));
        $page = max(1, $request->query->getInt('page', 1));

        if ($notification->getUser()?->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Accès interdit à cette notification.');
        }

        if (!$this->isCsrfTokenValid('notification_delete_'.$notification->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new Response('CSRF invalid', Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_notifications_index');
        }

        $entityManager->remove($notification);
        $entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->renderNotificationsPartial($filter, $user, $request, $notificationRepository);
        }

        $this->addFlash('success', 'Notification supprimée.');

        return $this->redirectToRoute('app_notifications_index', [
            'filter' => $filter,
            'page' => $page,
        ]);
    }

    #[Route('/read-all', name: 'app_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $this->normalizeFilter($request->query->get('filter'));
        $page = max(1, $request->query->getInt('page', 1));

        if (!$this->isCsrfTokenValid('notification_read_all', (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new Response('CSRF invalid', Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_notifications_index', [
                'filter' => $filter,
                'page' => $page,
            ]);
        }

        $updatedCount = $notificationRepository->markAllReadForUser($user);

        if ($request->isXmlHttpRequest()) {
            return $this->renderNotificationsPartial($filter, $user, $request, $notificationRepository);
        }

        if ($updatedCount > 0) {
            $this->addFlash('success', sprintf('%d notification(s) marquée(s) comme lue(s).', $updatedCount));
        } else {
            $this->addFlash('info', 'Aucune notification non lue à marquer.');
        }

        return $this->redirectToRoute('app_notifications_index', [
            'filter' => $filter,
            'page' => $page,
        ]);
    }

    private function renderNotificationsPartial(?string $filter, User $user, Request $request, NotificationRepository $notificationRepository): Response
    {
        $filter = $this->normalizeFilter($filter);
        $requestedPage = max(1, $request->query->getInt('page', 1));
        $isRead = $this->resolveReadFilter($filter);

        $total = $notificationRepository->countForUser($user, $isRead);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $notifications = $notificationRepository->findForUserOrderedPaginated($user, $isRead, $currentPage, self::PER_PAGE);
        $unreadCount = $notificationRepository->countUnreadForUser($user);

        if ($request->isXmlHttpRequest()) {
            return $this->render('notification/_dynamic_content.html.twig', [
                'notifications' => $notifications,
                'currentFilter' => $filter,
                'unreadCount' => $unreadCount,
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
            ]);
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'currentFilter' => $filter,
            'unreadCount' => $unreadCount,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être authentifié.');
        }

        return $user;
    }

    private function normalizeFilter(?string $filter): string
    {
        return match ($filter) {
            self::FILTER_ALL,
            self::FILTER_READ,
            self::FILTER_UNREAD => $filter,
            default => self::FILTER_UNREAD,
        };
    }

    private function resolveReadFilter(string $filter): ?bool
    {
        return match ($filter) {
            self::FILTER_READ => true,
            self::FILTER_UNREAD => false,
            default => null,
        };
    }
}
