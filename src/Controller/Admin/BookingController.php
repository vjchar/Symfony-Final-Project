<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Service\Booking\BookingCancellationManager;
use App\Service\Booking\BookingModificationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-bookings.php, its pending modification and cancellation queues.
 * (The booking list and status transitions arrive with the admin bookings step.)
 */
#[IsGranted('ROLE_ADMIN')]
final class BookingController extends AbstractController
{
    #[Route('/admin/bookings', name: 'app_admin_bookings', methods: ['GET'])]
    public function index(BookingModificationRequestRepository $modifications, BookingCancellationRequestRepository $cancellations): Response
    {
        return $this->render('admin/booking/index.html.twig', [
            'modifications' => $modifications->findPending(),
            'cancellations' => $cancellations->findPending(),
        ]);
    }

    #[Route('/admin/bookings/modifications/{id}/review', name: 'app_admin_modification_review', methods: ['POST'])]
    public function reviewModification(
        int $id,
        Request $request,
        #[CurrentUser] User $admin,
        BookingModificationRequestRepository $modifications,
        BookingModificationManager $manager,
    ): Response {
        $modification = $modifications->find($id);
        $this->review($request, 'review-modification-'.$id, 'Booking modification request reviewed.', static function (string $decision, string $note) use ($modification, $manager, $admin): void {
            if (null === $modification) {
                throw new \DomainException('That modification request is no longer pending.');
            }
            $manager->review($modification, $decision, $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#modification-queue', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/bookings/cancellations/{id}/review', name: 'app_admin_cancellation_review', methods: ['POST'])]
    public function reviewCancellation(
        int $id,
        Request $request,
        #[CurrentUser] User $admin,
        BookingCancellationRequestRepository $cancellations,
        BookingCancellationManager $manager,
    ): Response {
        $cancellation = $cancellations->find($id);
        $this->review($request, 'review-cancellation-'.$id, 'Cancellation request reviewed.', static function (string $decision, string $note) use ($cancellation, $manager, $admin): void {
            if (null === $cancellation) {
                throw new \DomainException('That cancellation request is no longer pending.');
            }
            $manager->review($cancellation, $decision, $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#cancellation-queue', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param callable(string, string): void $review gets the decision and admin note
     */
    private function review(Request $request, string $tokenId, string $success, callable $review): void
    {
        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid($tokenId, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return;
        }

        try {
            $review($payload->getString('decision'), $payload->getString('admin_note'));
            $this->addFlash('success', $success);
        } catch (\InvalidArgumentException|\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }
    }
}
