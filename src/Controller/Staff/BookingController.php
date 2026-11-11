<?php

namespace App\Controller\Staff;

use App\Entity\User;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingStatusManager;
use App\Service\Rental\RentalDesk;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff prepare confirmed bookings (mark the vehicle ready) and close
 * uncollected ones as no-shows. Rejecting, cancelling and reviewing customer
 * change requests move money, so they stay on the admin bookings page.
 */
#[IsGranted('ROLE_STAFF')]
#[Route('/staff/bookings')]
final class BookingController extends AbstractController
{
    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    #[Route('', name: 'app_staff_bookings', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable();

        return $this->render('staff/booking/index.html.twig', [
            'preparing' => $this->bookings->findAwaitingPreparation(),
            'uncollected' => array_values(array_filter(
                $this->bookings->findForStatusControls(),
                static fn ($booking): bool => \in_array($booking->getStatus(), ['confirmed', 'ready'], true) && $booking->getPickupAt() < $now,
            )),
            'upcoming' => array_values(array_filter(
                $this->bookings->findForStatusControls(),
                static fn ($booking): bool => $booking->getPickupAt() >= $now,
            )),
        ]);
    }

    #[Route('/{id}/ready', name: 'app_staff_booking_ready', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markReady(int $id, Request $request, #[CurrentUser] User $staff, RentalDesk $desk): Response
    {
        $this->act($request, 'booking-ready-'.$id, 'Vehicle marked ready. The customer has been notified.', function (string $note) use ($id, $desk, $staff): void {
            $desk->markReady($this->bookings->find($id) ?? throw new \DomainException('That booking status transition is not allowed.'), $staff, $note);
        });

        return $this->redirect($this->generateUrl('app_staff_bookings').'#preparation-queue', Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/no-show', name: 'app_staff_booking_no_show', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function noShow(int $id, Request $request, #[CurrentUser] User $staff, BookingStatusManager $statuses): Response
    {
        $this->act($request, 'booking-no-show-'.$id, 'Booking marked as a no-show.', function (string $note) use ($id, $statuses, $staff): void {
            $statuses->markNoShow($this->bookings->find($id) ?? throw new \DomainException('Only a confirmed or ready booking can be marked as a no-show.'), $staff, $note);
        });

        return $this->redirect($this->generateUrl('app_staff_bookings').'#uncollected', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param callable(string): void $action gets the staff note
     */
    private function act(Request $request, string $tokenId, string $success, callable $action): void
    {
        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid($tokenId, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return;
        }

        try {
            $action($payload->getString('note'));
            $this->addFlash('success', $success);
        } catch (\InvalidArgumentException|\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }
    }
}
