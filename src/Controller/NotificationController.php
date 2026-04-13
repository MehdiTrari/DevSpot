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
    #[Route('', name: 'app_notifications_index', methods: ['GET'])]
    public function index(Request $request, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $request->query->get('filter');

        $isRead = null;
        if ('read' === $filter) {
            $isRead = true;
        }
        if ('unread' === $filter) {
            $isRead = false;
        }

        $notifications = $notificationRepository->findForUserOrdered($user, $isRead);
        $unreadCount = $notificationRepository->countUnreadForUser($user);

        if ($request->isXmlHttpRequest()) {
            return $this->render('notification/_dynamic_content.html.twig', [
                'notifications' => $notifications,
                'currentFilter' => $filter,
                'unreadCount' => $unreadCount,
            ]);
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'currentFilter' => $filter,
            'unreadCount' => $unreadCount,
        ]);
    }

    #[Route('/{id}/read', name: 'app_notifications_mark_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request, EntityManagerInterface $entityManager, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $request->query->get('filter');

        if ($notification->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Accès interdit à cette notification.');
        }

        if (!$this->isCsrfTokenValid('notification_read_' . $notification->getId(), (string) $request->request->get('_token'))) {
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
        ]);
    }

    #[Route('/read-all', name: 'app_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, NotificationRepository $notificationRepository): Response
    {
        $user = $this->getAuthenticatedUser();
        $filter = $request->query->get('filter');

        if (!$this->isCsrfTokenValid('notification_read_all', (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new Response('CSRF invalid', Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_notifications_index', [
                'filter' => $filter,
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
        ]);
    }

    private function renderNotificationsPartial(?string $filter, User $user, Request $request, NotificationRepository $notificationRepository): Response
    {
        $isRead = null;
        if ('read' === $filter) {
            $isRead = true;
        }
        if ('unread' === $filter) {
            $isRead = false;
        }

        $notifications = $notificationRepository->findForUserOrdered($user, $isRead);
        $unreadCount = $notificationRepository->countUnreadForUser($user);

        if ($request->isXmlHttpRequest()) {
            return $this->render('notification/_dynamic_content.html.twig', [
                'notifications' => $notifications,
                'currentFilter' => $filter,
                'unreadCount' => $unreadCount,
            ]);
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'currentFilter' => $filter,
            'unreadCount' => $unreadCount,
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
}
