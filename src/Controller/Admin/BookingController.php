<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingCancellationManager;
use App\Service\Booking\BookingModificationManager;
use App\Service\Booking\BookingStatusManager;
use App\Service\Rental\RentalDesk;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-bookings.php: its pending modification and cancellation queues,
 * marking a confirmed booking's vehicle ready for the rental desk, and the
 * status controls for every booking before checkout: reject a pending one,
 * cancel one, or mark an uncollected one as a no-show.
 */
#[IsGranted('ROLE_ADMIN')]
final class BookingController extends AbstractController
{
    #[Route('/admin/bookings', name: 'app_admin_bookings', methods: ['GET'])]
    public function index(BookingModificationRequestRepository $modifications, BookingCancellationRequestRepository $cancellations, BookingRepository $bookings): Response
    {
        return $this->render('admin/booking/index.html.twig', [
            'modifications' => $modifications->findPending(),
            'cancellations' => $cancellations->findPending(),
            'preparing' => $bookings->findAwaitingPreparation(),
            'controls' => $bookings->findForStatusControls(),
            'now' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/admin/bookings/{id}/reject', name: 'app_admin_booking_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request, #[CurrentUser] User $admin, BookingRepository $bookings, BookingStatusManager $statuses): Response
    {
        $booking = $bookings->find($id);
        $this->review($request, 'booking-reject-'.$id, 'Booking rejected.', static function (string $decision, string $note) use ($booking, $statuses, $admin): void {
            $statuses->reject($booking ?? throw new \DomainException('Only a pending booking can be rejected.'), $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#status-controls', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/bookings/{id}/cancel', name: 'app_admin_booking_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] User $admin, BookingRepository $bookings, BookingStatusManager $statuses): Response
    {
        $booking = $bookings->find($id);
        $this->review($request, 'booking-cancel-'.$id, 'Booking cancelled.', static function (string $decision, string $note) use ($booking, $statuses, $admin): void {
            $statuses->cancel($booking ?? throw new \DomainException('Only a booking that has not been picked up yet can be cancelled.'), $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#status-controls', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/bookings/{id}/no-show', name: 'app_admin_booking_no_show', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function noShow(int $id, Request $request, #[CurrentUser] User $admin, BookingRepository $bookings, BookingStatusManager $statuses): Response
    {
        $booking = $bookings->find($id);
        $this->review($request, 'booking-no-show-'.$id, 'Booking marked as a no-show.', static function (string $decision, string $note) use ($booking, $statuses, $admin): void {
            $statuses->markNoShow($booking ?? throw new \DomainException('Only a confirmed or ready booking can be marked as a no-show.'), $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#status-controls', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/bookings/{id}/ready', name: 'app_admin_booking_ready', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markReady(int $id, Request $request, #[CurrentUser] User $admin, BookingRepository $bookings, RentalDesk $desk): Response
    {
        $booking = $bookings->find($id);
        $this->review($request, 'booking-ready-'.$id, 'Booking status updated to Ready.', static function (string $decision, string $note) use ($booking, $desk, $admin): void {
            if (null === $booking) {
                throw new \DomainException('That booking status transition is not allowed.');
            }
            $desk->markReady($booking, $admin, $note);
        });

        return $this->redirect($this->generateUrl('app_admin_bookings').'#preparation-queue', Response::HTTP_SEE_OTHER);
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
