<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends AbstractController
{
    #[Route('/messages/entry-point', name: 'app_chat_entry_point', methods: ['GET'])]
    public function entryPoint(MessageRepository $messageRepository): JsonResponse
    {
        $user = $this->getUser();
        if (
            !$user instanceof User
            || (
                !in_array('ROLE_APPLICANT', $user->getRoles(), true)
                && !in_array('ROLE_RECRUITER', $user->getRoles(), true)
            )
        ) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $unreadCount = $messageRepository->countUnreadForUser($user);

        return new JsonResponse([
            'ok' => true,
            'unreadCount' => $unreadCount,
            'html' => $this->renderView('_chat_entry_point.html.twig', [
                'user' => $user,
                'unreadCount' => $unreadCount,
            ]),
        ]);
    }
}
