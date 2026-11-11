<?php

namespace App\Service\Rental;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\RentalInspection;
use App\Entity\RentalSettlement;
use App\Entity\User;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\RentalInspectionRepository;
use App\Service\AuditLogger;
use App\Service\Booking\BookingConfirmer;
use App\Service\Booking\RentalAdjustmentManager;
use App\Service\Booking\RunsInTransaction;
use App\Service\NotificationCenter;
use App\Service\Payment\PaymentLedger;
use App\Service\Payment\RefundManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 rental-service.php and admin-rentals.php: the rental desk prepares the
 * vehicle (ready), releases it with a pickup inspection (active), takes it
 * back with a return inspection (returned), settles the security deposit
 * against the return charges and closes the rental (completed).
 */
final class RentalDesk
{
    use RunsInTransaction;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly RentalInspectionRepository $inspections,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly BookingCancellationRequestRepository $cancellations,
        private readonly PaymentRepository $payments,
        private readonly PaymentRefundRepository $refunds,
        private readonly PaymentLedger $ledger,
        private readonly BookingConfirmer $confirmer,
        private readonly RentalAdjustmentManager $adjustmentManager,
        private readonly RefundManager $refundManager,
        private readonly SettlementRefresher $settlementRefresher,
        private readonly VehicleStatusSync $vehicles,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * v15 update_booking_status(…, 'ready'): the prepared vehicle can be
     * handed over. Requirements, open requests and the vehicle are checked again.
     *
     * @throws \DomainException with the v15 message
     */
    public function markReady(Booking $booking, User $admin, string $notes = ''): void
    {
        $this->transactional(function () use ($booking, $notes): void {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if ('confirmed' !== $booking->getStatus()) {
                throw new \DomainException('That booking status transition is not allowed.');
            }
            if (null !== $this->modifications->findUnresolvedFor($booking)) {
                throw new \DomainException('Resolve the pending booking modification before marking this vehicle ready.');
            }
            if (true === $this->cancellations->findLatestFor($booking)?->isPending()) {
                throw new \DomainException('Resolve the pending cancellation request before marking this vehicle ready.');
            }
            if (!$this->confirmer->licenseCovers($booking, $booking->getReturnAt())) {
                throw new \DomainException(BookingConfirmer::LICENSE_TOO_SHORT);
            }
            if (!$this->confirmer->isReady($booking)) {
                throw new \DomainException('Payment and required documents must still be verified before the vehicle can be marked ready.');
            }
            $vehicle = $booking->getVehicle();
            if (!$vehicle->isActive() || \in_array($vehicle->getAvailabilityStatus(), ['maintenance', 'unavailable', 'rented'], true)) {
                throw new \DomainException('The vehicle is not operationally available to mark ready.');
            }
            if ($this->bookings->countActiveFor($vehicle, $booking) > 0) {
                throw new \DomainException('This vehicle is still assigned to another active rental.');
            }

            $booking->markReady($notes);
            $this->entityManager->flush();
            $this->vehicles->sync($vehicle);

            $handover = $booking->getPickupAt()->format('M j, Y g:i A');
            $this->notifications->notifyUser(
                $booking->getUser(),
                $booking->isDelivery() ? 'Your Vehicle Is Ready for Delivery' : 'Your Vehicle Is Ready for Pickup',
                $booking->isDelivery()
                    ? sprintf('Your booked vehicle for %s has been prepared and is ready for delivery. Scheduled delivery: %s. Delivery location: %s.', $booking->getReference(), $handover, $booking->getDeliveryAddress() ?: 'your booking address')
                    : sprintf('Your booked vehicle for %s is now ready for pickup. Scheduled pickup: %s. Pickup location: %s.', $booking->getReference(), $handover, $booking->getPickupLocation()),
                'booking',
                $booking,
            );
            $this->entityManager->flush();
        });

        $this->auditLogger->log($booking->isDelivery() ? 'vehicle_ready_for_delivery' : 'vehicle_ready_for_pickup', 'booking', $booking->getId(), [...AuditLogger::actor($admin), 'pickup_method' => $booking->getPickupMethod()], $admin);
        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => 'confirmed', 'to' => 'ready', ...AuditLogger::actor($admin)], $admin);
    }

    /**
     * v15 checkout_booking(): the pickup inspection is recorded and the rental starts.
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function checkOut(Booking $booking, InspectionReading $reading, User $admin): void
    {
        $this->transactional(function () use ($booking, $reading, $admin): void {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if ('ready' === $booking->getStatus() && !$this->confirmer->licenseCovers($booking, $booking->getReturnAt())) {
                throw new \DomainException(BookingConfirmer::LICENSE_TOO_SHORT);
            }
            $booking->checkOut();
            $this->record($booking, RentalInspection::TYPE_CHECKOUT, $reading, $admin);
            $this->entityManager->flush();
            $this->vehicles->sync($booking->getVehicle());
            $this->notifications->notifyUser($booking->getUser(), 'Vehicle checked out', sprintf('Your rental for booking %s is now active. Drive safely!', $booking->getReference()), 'rental', $booking);
            $this->entityManager->flush();
        });

        $this->auditLogger->log('rental_checked_out', 'booking', $booking->getId(), ['from' => 'ready', 'to' => 'active', ...self::readingDetails($reading), ...AuditLogger::actor($admin)], $admin);
    }

    /**
     * v15 checkin_booking(): the return inspection is recorded, a pending early
     * return is applied as it happened, any other open request is closed, and
     * the deposit waits for settlement.
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function checkIn(Booking $booking, InspectionReading $reading, User $admin): void
    {
        $this->transactional(function () use ($booking, $reading, $admin): void {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if ('active' === $booking->getStatus()) {
                $this->adjustmentManager->applyPendingEarlyReturnAtCheckIn($booking, $admin);
            }
            $booking->checkIn();
            $checkout = $this->inspections->findOneForBooking($booking, RentalInspection::TYPE_CHECKOUT);
            if (null === $checkout || $reading->odometer < $checkout->getOdometer()) {
                throw new \InvalidArgumentException('Return odometer cannot be below the checkout odometer.');
            }
            $this->record($booking, RentalInspection::TYPE_CHECKIN, $reading, $admin);
            foreach ($this->adjustments->findForBooking($booking) as $adjustment) {
                $adjustment->closeOnReturn();
            }
            $this->entityManager->flush();
            $this->vehicles->sync($booking->getVehicle());
            $this->notifications->notifyUser($booking->getUser(), 'Vehicle returned', sprintf('The return inspection for booking %s has been recorded.', $booking->getReference()), 'rental', $booking);
            $this->entityManager->flush();
        });

        $this->auditLogger->log('rental_checked_in', 'booking', $booking->getId(), ['from' => 'active', 'to' => 'returned', ...self::readingDetails($reading), 'extra_charges' => $reading->extraCharges, ...AuditLogger::actor($admin)], $admin);
    }

    /**
     * v15 rental_settlement_preview(): the verified deposit still held and the
     * charge the return inspection recorded.
     */
    public function preview(Booking $booking): SettlementPreview
    {
        $held = 0;
        foreach ($this->payments->findForBooking($booking) as $payment) {
            if ($payment->isPaid() && Payment::TYPE_DEPOSIT === $payment->getPaymentType()) {
                $held += $this->refunds->refundableBalance($payment);
            }
        }

        return new SettlementPreview(
            $held,
            $this->inspections->findOneForBooking($booking, RentalInspection::TYPE_CHECKIN)?->getExtraCharges() ?? 0,
        );
    }

    /**
     * v15 finalize_return_settlement(): the return charge, broken down into
     * damage, fuel, late and other, comes out of the deposit first. What is
     * left of the deposit becomes a pending refund; anything beyond it is a
     * balance the customer pays as a return charge.
     *
     * @param array{damage: string, fuel: string, late: string, other: string} $charges
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function finalizeSettlement(Booking $booking, array $charges, string $notes, User $admin): RentalSettlement
    {
        $settlement = $this->transactional(function () use ($booking, $charges, $notes, $admin): RentalSettlement {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if ('returned' !== $booking->getStatus()) {
                throw new \DomainException('Only a returned rental can be settled.');
            }
            if (null !== $booking->getSettlement()) {
                throw new \DomainException('This rental already has a finalized settlement.');
            }
            if (null === $this->inspections->findOneForBooking($booking, RentalInspection::TYPE_CHECKIN)) {
                throw new \DomainException('Complete the return inspection before final settlement.');
            }

            $preview = $this->preview($booking);
            $amounts = array_map(static fn (string $value): int => max(0, (int) trim($value)), $charges);
            if (array_sum($amounts) !== $preview->inspectionCharge) {
                throw new \InvalidArgumentException(sprintf('Damage, fuel, late, and other deductions must add up to the return-inspection extra charge of ₱%s.', number_format($preview->inspectionCharge)));
            }

            $settlement = $booking->finalizeSettlement($preview->depositHeld, $amounts['damage'], $amounts['fuel'], $amounts['late'], $amounts['other'], $admin, $notes);
            $this->entityManager->flush();

            $this->refundManager->allocate(
                $booking,
                $settlement->getDepositRefundAmount(),
                PaymentRefund::TYPE_SECURITY_DEPOSIT,
                'Unused security deposit after final return settlement',
                $admin,
                paymentTypes: [Payment::TYPE_DEPOSIT],
            );

            $this->notifications->notifyUser(
                $booking->getUser(),
                'Return Settlement Complete',
                sprintf('Return settlement for booking %s is complete. %s', $booking->getReference(), match (true) {
                    $settlement->getDepositRefundAmount() > 0 => sprintf('Security deposit refund: ₱%s (pending processing).', number_format($settlement->getDepositRefundAmount())),
                    $settlement->getOutstandingBalance() > 0 => sprintf('Outstanding balance: ₱%s.', number_format($settlement->getOutstandingBalance())),
                    default => 'No refund or additional balance is due.',
                }),
                'settlement',
                $booking,
            );
            $this->entityManager->flush();

            return $settlement;
        });

        $this->auditLogger->log('return_settlement_finalized', 'booking', $booking->getId(), [
            'deposit_paid' => $settlement->getDepositPaid(),
            'deductions' => $settlement->getTotalDeductions(),
            'deposit_refund' => $settlement->getDepositRefundAmount(),
            'outstanding' => $settlement->getOutstandingBalance(),
        ], $admin);

        return $settlement;
    }

    /**
     * v15 admin-rentals.php "complete": only once the settlement is settled
     * (deposit refunded, return balance paid) and nothing else is owed.
     *
     * @throws \DomainException with the v15 message
     */
    public function complete(Booking $booking, User $admin, string $notes = ''): void
    {
        $this->transactional(function () use ($booking, $notes): void {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            $settlement = $this->settlementRefresher->refresh($booking);
            $this->entityManager->flush();
            if (null === $settlement) {
                throw new \DomainException('Finalize the security-deposit settlement before completing this rental.');
            }
            if (!$settlement->isSettled()) {
                throw new \DomainException('Finish the deposit refund or outstanding return balance before completing this rental.');
            }
            if ($this->ledger->summary($booking)->amountDue() > 0) {
                throw new \DomainException('Verify all payment obligations and outstanding return charges before completing this booking.');
            }

            $booking->complete($notes);
            $this->entityManager->flush();
            $this->vehicles->sync($booking->getVehicle());
            $this->notifications->notifyUser($booking->getUser(), 'Booking completed', sprintf('Booking %s is now completed.', $booking->getReference()), 'booking', $booking);
            $this->entityManager->flush();
        });

        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => 'returned', 'to' => 'completed', ...AuditLogger::actor($admin)], $admin);
    }

    private function record(Booking $booking, string $type, InspectionReading $reading, User $admin): void
    {
        $inspection = $this->inspections->findOneForBooking($booking, $type) ?? new RentalInspection($booking, $type, $admin);
        $inspection->record($reading->odometer, $reading->fuelPercent, $reading->bodyCondition, $reading->notes, $reading->damageNotes, $reading->extraCharges, $admin);
        $this->entityManager->persist($inspection);
    }

    /**
     * What an inspection recorded, for the audit trail.
     *
     * @return array<string, int|string>
     */
    private static function readingDetails(InspectionReading $reading): array
    {
        return array_filter([
            'odometer' => $reading->odometer,
            'fuel_percent' => $reading->fuelPercent,
            'condition' => $reading->bodyCondition,
            'damage_notes' => $reading->damageNotes,
        ], static fn (int|string $value): bool => '' !== $value);
    }
}
