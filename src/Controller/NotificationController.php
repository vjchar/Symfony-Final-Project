<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Booking\BookingJourneyResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 notifications.php: every signed-in account's updates, newest first.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class NotificationController extends AbstractController
{
    #[Route('/account/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, NotificationRepository $notifications, BookingJourneyResolver $journeys): Response
    {
        return $this->render('notification/index.html.twig', [
            'notifications' => array_map(
                static fn (Notification $notification): array => ['notification' => $notification, 'action' => $journeys->notificationAction($notification)],
                $notifications->findForUser($user),
            ),
        ]);
    }

    #[Route('/account/notifications/read', name: 'app_notifications_read', methods: ['POST'])]
    public function markRead(Request $request, #[CurrentUser] User $user, NotificationRepository $notifications): Response
    {
        if ($this->isCsrfTokenValid('notifications-read', $request->getPayload()->getString('_token'))) {
            $notifications->markAllRead($user);
            $this->addFlash('success', 'All notifications marked as read.');
        } else {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        }

        return $this->redirectToRoute('app_notifications', [], Response::HTTP_SEE_OTHER);
    }
}
