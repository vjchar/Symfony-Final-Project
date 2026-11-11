<?php

namespace App\Controller\Staff;

use App\Entity\Booking;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\RentalInspectionRepository;
use App\Service\Rental\InspectionReading;
use App\Service\Rental\LateReturnPolicy;
use App\Service\Rental\RentalDesk;
use App\Service\Rental\ReturnChargeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff rental desk: hand prepared vehicles over with a pickup inspection
 * and take them back with a return inspection. The deposit settlement and
 * closing the rental stay with the administrator.
 */
#[IsGranted('ROLE_STAFF')]
#[Route('/staff/rentals')]
final class RentalController extends AbstractController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly RentalDesk $desk,
    ) {
    }

    #[Route('', name: 'app_staff_rentals', methods: ['GET'])]
    public function index(
        RentalInspectionRepository $inspections,
        LateReturnPolicy $lateReturns,
        ReturnChargeCalculator $returnCharges,
        #[MapQueryParameter] string $reference = '',
    ): Response {
        $now = new \DateTimeImmutable();
        $selected = '' !== $reference ? $this->bookings->findOneBy(['reference' => $reference]) : null;
        if (null !== $selected && !\in_array($selected->getStatus(), ['ready', 'active', 'returned'], true)) {
            $selected = null;
        }
        $isActive = 'active' === $selected?->getStatus();

        return $this->render('staff/rental/index.html.twig', [
            'rentals' => $this->bookings->findForRentalDesk(),
            'selected' => $selected,
            'inspections' => null === $selected ? [] : $inspections->findForBooking($selected),
            'km_allowance' => null === $selected ? 0 : $returnCharges->kmAllowance($selected),
            'excess_km_rate' => $returnCharges->excessKmRate,
            'refuel_rate' => $returnCharges->refuelRate,
            'late_days' => $isActive ? $lateReturns->lateDays($selected, $now) : 0,
            'late_fee' => $isActive ? $lateReturns->lateFee($selected, $now) : 0,
            'now' => $now,
        ]);
    }

    #[Route('/{reference}/checkout', name: 'app_staff_rental_checkout', methods: ['POST'])]
    public function checkout(string $reference, Request $request, #[CurrentUser] User $staff): Response
    {
        return $this->inspect($reference, $request, 'checkout', 'Pickup inspection saved. The rental is now active.', function (Booking $booking, InspectionReading $reading) use ($staff): void {
            $this->desk->checkOut($booking, $reading, $staff);
        });
    }

    #[Route('/{reference}/checkin', name: 'app_staff_rental_checkin', methods: ['POST'])]
    public function checkin(string $reference, Request $request, #[CurrentUser] User $staff): Response
    {
        return $this->inspect($reference, $request, 'checkin', 'Return inspection saved. An administrator will settle the deposit.', function (Booking $booking, InspectionReading $reading) use ($staff): void {
            $this->desk->checkIn($booking, $reading, $staff);
        });
    }

    /**
     * Checks the token, records one inspection and goes back to the selected rental.
     *
     * @param callable(Booking, InspectionReading): void $record
     */
    private function inspect(string $reference, Request $request, string $type, string $success, callable $record): Response
    {
        $booking = $this->bookings->findOneBy(['reference' => $reference]);
        if (null === $booking) {
            $this->addFlash('danger', 'Booking not found.');

            return $this->redirectToRoute('app_staff_rentals', status: Response::HTTP_SEE_OTHER);
        }

        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid('rental-'.$type.'-'.$booking->getId(), $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } else {
            try {
                $record($booking, InspectionReading::fromInput(
                    $payload->getString('odometer'),
                    $payload->getString('fuel_percent'),
                    $payload->getString('body_condition'),
                    $payload->getString('notes'),
                    $payload->getString('damage_notes'),
                    'checkin' === $type ? $payload->getString('extra_charges', '0') : '0',
                ));
                $this->addFlash('success', $success);
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_staff_rentals', ['reference' => $reference], Response::HTTP_SEE_OTHER);
    }
}
