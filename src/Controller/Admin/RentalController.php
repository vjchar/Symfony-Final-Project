<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\RentalInspectionRepository;
use App\Repository\RentalSettlementRepository;
use App\Service\Booking\RentalAdjustmentManager;
use App\Service\Payment\PaymentLedger;
use App\Service\Rental\InspectionReading;
use App\Service\Rental\RentalDesk;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-rentals.php: the rental adjustment queue, the desk queue of
 * ready, active and returned rentals, and for the selected one its checkout,
 * check-in, return settlement and completion.
 */
#[IsGranted('ROLE_ADMIN')]
final class RentalController extends AbstractController
{
    private const SESSION_EXPIRED = 'Your session expired. Refresh the page and try again.';

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly RentalDesk $desk,
    ) {
    }

    #[Route('/admin/rentals', name: 'app_admin_rentals', methods: ['GET'])]
    public function index(
        RentalAdjustmentRequestRepository $adjustments,
        RentalInspectionRepository $inspections,
        RentalSettlementRepository $settlements,
        PaymentRefundRepository $refunds,
        PaymentLedger $ledger,
        #[MapQueryParameter] string $reference = '',
    ): Response {
        $selected = '' !== $reference ? $this->bookings->findOneBy(['reference' => $reference]) : null;
        if (null !== $selected && !\in_array($selected->getStatus(), ['ready', 'active', 'returned'], true)) {
            $selected = null;
        }

        return $this->render('admin/rental/index.html.twig', [
            'adjustments' => $adjustments->findOpen(),
            'rentals' => $this->bookings->findForRentalDesk(),
            'selected' => $selected,
            'inspections' => null === $selected ? [] : $inspections->findForBooking($selected),
            'selected_adjustments' => null === $selected ? [] : $adjustments->findForBooking($selected),
            'settlement' => null === $selected ? null : $settlements->findForBooking($selected),
            'preview' => null === $selected ? null : $this->desk->preview($selected),
            'payment_summary' => null === $selected ? null : $ledger->summary($selected),
            'refunds' => null === $selected ? [] : $refunds->findForBooking($selected),
        ]);
    }

    #[Route('/admin/rentals/{reference}/checkout', name: 'app_admin_rental_checkout', methods: ['POST'])]
    public function checkout(string $reference, Request $request, #[CurrentUser] User $admin): Response
    {
        return $this->act($reference, $request, 'rental-checkout', 'Checkout inspection saved and rental activated.', function (Booking $booking, InputBag $payload) use ($admin): void {
            $this->desk->checkOut($booking, $this->reading($payload, false), $admin);
        });
    }

    #[Route('/admin/rentals/{reference}/checkin', name: 'app_admin_rental_checkin', methods: ['POST'])]
    public function checkin(string $reference, Request $request, #[CurrentUser] User $admin): Response
    {
        return $this->act($reference, $request, 'rental-checkin', 'Return inspection saved. Review any charges before completion.', function (Booking $booking, InputBag $payload) use ($admin): void {
            $this->desk->checkIn($booking, $this->reading($payload, true), $admin);
        });
    }

    #[Route('/admin/rentals/{reference}/settle', name: 'app_admin_rental_settle', methods: ['POST'])]
    public function settle(string $reference, Request $request, #[CurrentUser] User $admin): Response
    {
        return $this->act($reference, $request, 'rental-settle', 'Return settlement finalized. Any deposit refund or remaining balance is now tracked.', function (Booking $booking, InputBag $payload) use ($admin): void {
            $this->desk->finalizeSettlement($booking, [
                'damage' => $payload->getString('damage_charges'),
                'fuel' => $payload->getString('fuel_charges'),
                'late' => $payload->getString('late_charges'),
                'other' => $payload->getString('other_charges'),
            ], $payload->getString('settlement_notes'), $admin);
        });
    }

    #[Route('/admin/rentals/{reference}/complete', name: 'app_admin_rental_complete', methods: ['POST'])]
    public function complete(string $reference, Request $request, #[CurrentUser] User $admin): Response
    {
        return $this->act($reference, $request, 'rental-complete', 'Rental completed. The customer can now leave a verified review.', function (Booking $booking, InputBag $payload) use ($admin): void {
            $this->desk->complete($booking, $admin, $payload->getString('admin_notes'));
        });
    }

    #[Route('/admin/rentals/adjustments/{id}/review', name: 'app_admin_adjustment_review', methods: ['POST'])]
    public function review(
        int $id,
        Request $request,
        #[CurrentUser] User $admin,
        RentalAdjustmentRequestRepository $adjustments,
        RentalAdjustmentManager $manager,
    ): Response {
        $payload = $request->getPayload();
        $back = $this->redirectToRoute('app_admin_rentals', status: Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('review-adjustment-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);

            return $back;
        }

        try {
            $adjustment = $adjustments->find($id) ?? throw new \DomainException('This request is no longer eligible for review.');
            $manager->review($adjustment, $payload->getString('decision'), $admin, $payload->getString('admin_note'));
            $this->addFlash('success', 'Rental adjustment request reviewed.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $back;
    }

    /**
     * Checks the token, runs one desk action and goes back to the selected rental.
     *
     * @param callable(Booking, InputBag<string>): void $action
     */
    private function act(string $reference, Request $request, string $tokenId, string $success, callable $action): Response
    {
        $payload = $request->getPayload();
        $booking = $this->bookings->findOneBy(['reference' => $reference]);
        if (null === $booking) {
            $this->addFlash('danger', 'Booking not found.');

            return $this->redirectToRoute('app_admin_rentals', status: Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid($tokenId.'-'.$booking->getId(), $payload->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);
        } else {
            try {
                $action($booking, $payload);
                $this->addFlash('success', $success);
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_rentals', ['reference' => $reference], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param InputBag<string> $payload
     */
    private function reading(InputBag $payload, bool $checkin): InspectionReading
    {
        return InspectionReading::fromInput(
            $payload->getString('odometer'),
            $payload->getString('fuel_percent'),
            $payload->getString('body_condition'),
            $payload->getString('notes'),
            $payload->getString('damage_notes'),
            $checkin ? $payload->getString('extra_charges', '0') : '0',
        );
    }
}
