<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Repository\PromoRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Service\AuditLogger;
use App\Service\NotificationCenter;
use App\Service\Payment\RefundManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 rental-service.php, rental adjustments: while a rental is active the
 * customer may ask to return early or to extend it, and the rental desk
 * approves or rejects the request. An extension that costs nothing more is
 * activated on approval; one that costs more is activated once its payment
 * is verified (activatePaidExtension()). An approved early return is
 * activated at once: the booking takes the earlier return, is priced again
 * for fewer days, and what was paid for the unused days becomes a refund.
 * The real return is still recorded at check-in.
 */
final class RentalAdjustmentManager
{
    use RunsInTransaction;

    public const DECISIONS = ['approve', 'reject'];

    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    // the payments an early-return credit can go back to (never the security deposit)
    private const RENTAL_PAYMENT_TYPES = [Payment::TYPE_BALANCE, Payment::TYPE_EXTENSION, Payment::TYPE_MODIFICATION];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly MaintenanceRecordRepository $maintenance,
        private readonly PromoRepository $promos,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly PaymentRepository $payments,
        private readonly PaymentRefundRepository $refunds,
        private readonly RefundManager $refundManager,
        private readonly BookingConfirmer $confirmer,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationCenter $notifications,
    ) {
    }

    /**
     * v15 preview_rental_extension().
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function previewExtension(Booking $booking, User $user, string $date, string $time): ExtensionPricing
    {
        if (!$booking->isOwnedBy($user) || 'active' !== $booking->getStatus()) {
            throw new \DomainException('Only your active rental can be extended.');
        }
        $requestedReturnAt = $this->parseReturn($date, $time);
        $this->assertExtensionAvailable($booking, $requestedReturnAt);

        return $this->extensionPricing($booking, $requestedReturnAt);
    }

    /**
     * v15 create_rental_adjustment_request().
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function request(Booking $booking, User $user, string $type, string $date, string $time, string $reason): RentalAdjustmentRequest
    {
        if (!$booking->isOwnedBy($user) || 'active' !== $booking->getStatus()) {
            throw new \DomainException('Only your active rental can be adjusted.');
        }
        if (null !== $this->adjustments->findUnresolvedFor($booking)) {
            throw new \DomainException('Resolve the current rental adjustment request before creating another.');
        }
        if (!\in_array($type, RentalAdjustmentRequest::TYPES, true)) {
            throw new \InvalidArgumentException('Choose a valid rental adjustment.');
        }
        $requestedReturnAt = $this->parseReturn($date, $time);

        $priceDifference = 0;
        if (RentalAdjustmentRequest::TYPE_EARLY_RETURN === $type) {
            // v15 counts from started_at (the checkout), falling back to the scheduled pick-up
            if ($requestedReturnAt <= ($booking->getStartedAt() ?? $booking->getPickupAt()) || $requestedReturnAt >= $booking->getReturnAt()) {
                throw new \InvalidArgumentException('Choose a return time after vehicle release and before the current scheduled return.');
            }
            if ($requestedReturnAt <= new \DateTimeImmutable()) {
                throw new \InvalidArgumentException('Choose a future early-return time.');
            }
            // an estimate: the rental desk prices it again on approval
            $priceDifference = -$this->earlyReturnPricing($booking, $requestedReturnAt)->reduction;
        } else {
            $this->assertExtensionAvailable($booking, $requestedReturnAt);
            $priceDifference = $this->extensionPricing($booking, $requestedReturnAt)->priceDifference;
        }

        $adjustment = $this->transactional(function () use ($booking, $user, $type, $requestedReturnAt, $priceDifference, $reason): RentalAdjustmentRequest {
            $this->entityManager->lock($booking, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->adjustments->findUnresolvedFor($booking)) {
                throw new \DomainException('Resolve the current rental adjustment request before creating another.');
            }
            $adjustment = new RentalAdjustmentRequest($booking, $user, $type, $requestedReturnAt, $priceDifference, $reason);
            $this->entityManager->persist($adjustment);
            $booking->keepOriginalReturn();
            $this->notifications->notifyAdmins(
                RentalAdjustmentRequest::TYPE_EXTENSION === $type ? 'Rental extension requested' : 'Early return requested',
                sprintf('%s asked to change the return of booking %s to %s.', $user->getName(), $booking->getReference(), $requestedReturnAt->format('M j, Y g:i A')),
                'rental',
                $booking,
            );
            $this->entityManager->flush();

            return $adjustment;
        });

        $action = RentalAdjustmentRequest::TYPE_EXTENSION === $type ? 'rental_extension_requested' : 'early_return_requested';
        $this->auditLogger->log($action, 'rental_adjustment', $adjustment->getId(), ['booking_id' => $booking->getId()]);

        return $adjustment;
    }

    /**
     * v15 cancel_rental_adjustment_request().
     *
     * @throws \DomainException
     */
    public function withdraw(RentalAdjustmentRequest $adjustment, User $user): void
    {
        $adjustment->withdraw($user);
        $this->entityManager->flush();
        $this->auditLogger->log('rental_adjustment_cancelled', 'rental_adjustment', $adjustment->getId());
    }

    /**
     * v15 review_rental_adjustment_request(): an extension is checked and
     * priced again before it is approved; an early return is priced again,
     * applied to the booking and refunded for the unused days. The customer
     * is told the outcome either way.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function review(RentalAdjustmentRequest $adjustment, string $decision, User $admin, string $note = ''): void
    {
        if (!\in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Choose approve or reject.');
        }

        $action = $this->transactional(function () use ($adjustment, $decision, $admin, $note): string {
            $this->entityManager->refresh($adjustment, LockMode::PESSIMISTIC_WRITE);
            $booking = $adjustment->getBooking();
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if (!$adjustment->isPending() || 'active' !== $booking->getStatus()) {
                throw new \DomainException('This request is no longer eligible for review.');
            }
            $label = $adjustment->isExtension() ? 'Rental extension' : 'Early return';

            if ('reject' === $decision) {
                $adjustment->reject($admin, $note);
                $this->notifyCustomer($adjustment, $label.' not approved', sprintf(
                    'Your %s request for booking %s was not approved. Your scheduled return stays %s.%s',
                    mb_strtolower($label),
                    $booking->getReference(),
                    $booking->getReturnAt()->format('M j, Y g:i A'),
                    self::noteSuffix($note),
                ));
                $this->entityManager->flush();

                return 'rental_adjustment_rejected';
            }

            if (!$adjustment->isExtension()) {
                $this->approveEarlyReturn($adjustment, $admin, $note);

                return 'early_return_approved';
            }

            $this->entityManager->lock($booking->getVehicle(), LockMode::PESSIMISTIC_WRITE);
            $this->assertExtensionAvailable($booking, $adjustment->getRequestedReturnAt());
            $pricing = $this->extensionPricing($booking, $adjustment->getRequestedReturnAt());
            $adjustment->approve($admin, $note, $pricing->priceDifference);
            if (0 === $pricing->priceDifference) {
                $this->activateExtension($adjustment, $pricing, $admin);
            }
            $this->notifyCustomer($adjustment, 'Rental extension approved', sprintf(
                0 === $pricing->priceDifference
                    ? 'Booking %s now has a return time of %s.%s'
                    : 'Your extension of booking %s to %s was approved. Pay the extension balance to activate the new return time.%s',
                $booking->getReference(),
                $pricing->requestedReturnAt->format('M j, Y g:i A'),
                self::noteSuffix($note),
            ));
            $this->entityManager->flush();

            return 'rental_extension_approved';
        });

        $this->auditLogger->log($action, 'rental_adjustment', $adjustment->getId(), ['admin_id' => $admin->getId(), 'price_difference' => $adjustment->getPriceDifference()], $admin);
    }

    /**
     * The rental priced again up to an earlier return, at the booking's own
     * daily rate, so an early return never costs more than the booking.
     *
     * @throws \InvalidArgumentException when the return is not between pickup and the current return
     */
    public function earlyReturnPricing(Booking $booking, \DateTimeImmutable $requestedReturnAt): EarlyReturnPricing
    {
        if ($requestedReturnAt <= $booking->getPickupAt() || $requestedReturnAt >= $booking->getReturnAt()) {
            throw new \InvalidArgumentException('Choose a return time after vehicle release and before the current scheduled return.');
        }
        $days = max(1, (int) ceil(($requestedReturnAt->getTimestamp() - $booking->getPickupAt()->getTimestamp()) / 86400));
        $dailyRate = intdiv($booking->getSubtotal(), $booking->getDays());
        $subtotal = min($booking->getSubtotal(), $dailyRate * $days);
        // the booking's promotion is kept even if it has since ended, as for extensions
        $promo = '' === $booking->getPromoCode() ? null : $this->promos->findOneBy(['code' => $booking->getPromoCode()]);
        $discount = $promo?->discountFor($subtotal) ?? min($booking->getDiscount(), $subtotal);
        $total = min($booking->getTotal(), $subtotal + $booking->getAddonsTotal() + $booking->getDeliveryFee() - $discount);

        return new EarlyReturnPricing($requestedReturnAt, $days, $subtotal, $booking->getAddonsTotal(), $discount, $total, $booking->getTotal() - $total);
    }

    /**
     * Runs inside review()'s transaction with the booking locked.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    private function approveEarlyReturn(RentalAdjustmentRequest $adjustment, User $admin, string $note): void
    {
        if ($adjustment->getRequestedReturnAt() <= new \DateTimeImmutable()) {
            throw new \DomainException('The requested early-return time has already passed. Reject the request or record the return at check-in.');
        }
        $this->activateEarlyReturn($adjustment, $adjustment->getRequestedReturnAt(), $admin, $note);
    }

    /**
     * RentalDesk::checkIn(): the vehicle is back before the desk reviewed a
     * pending early return, so the return is applied as it actually happened.
     * Runs inside the check-in transaction, before the booking is marked returned.
     */
    public function applyPendingEarlyReturnAtCheckIn(Booking $booking, User $admin): void
    {
        $adjustment = $this->adjustments->findOneBy(
            ['booking' => $booking, 'requestType' => RentalAdjustmentRequest::TYPE_EARLY_RETURN, 'status' => RentalAdjustmentRequest::STATUS_PENDING],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
        if (null === $adjustment) {
            return;
        }
        // billed from pickup, so a vehicle back before pickup still costs one day
        $returnedAt = max(new \DateTimeImmutable(), $booking->getPickupAt()->modify('+1 second'));
        if ($returnedAt >= $booking->getReturnAt()) {
            return; // not early any more: check-in closes the request
        }
        $this->activateEarlyReturn($adjustment, $returnedAt, $admin, 'Applied at check-in: the vehicle was returned before the request was reviewed.');
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    private function activateEarlyReturn(RentalAdjustmentRequest $adjustment, \DateTimeImmutable $returnAt, User $admin, string $note): void
    {
        $booking = $adjustment->getBooking();
        $pricing = $this->earlyReturnPricing($booking, $returnAt);
        // what was paid towards the rental (never the deposit), beyond the new total
        $credit = min($pricing->reduction, max(0, $this->refundableRentalPayments($booking) - $pricing->total));

        $adjustment->approve($admin, $note, -$pricing->reduction);
        $booking->shortenTo($pricing->requestedReturnAt, $pricing->subtotal, $pricing->addonsTotal, $pricing->discount, $pricing->total);
        $adjustment->markActivated(-$pricing->reduction);
        $this->entityManager->flush();

        $reference = $booking->getReference();
        $this->refundManager->allocate(
            $booking,
            $credit,
            PaymentRefund::TYPE_EARLY_RETURN,
            sprintf('Unused rental days after the approved early return of booking %s', $reference),
            $admin,
            paymentTypes: self::RENTAL_PAYMENT_TYPES,
        );
        $this->notifyCustomer($adjustment, 'Early return approved', sprintf(
            'Booking %s now ends on %s. %s%s',
            $reference,
            $pricing->requestedReturnAt->format('M j, Y g:i A'),
            match (true) {
                $credit > 0 => sprintf('A refund of ₱%s for the unused days is awaiting processing.', number_format($credit)),
                $pricing->reduction > 0 => sprintf('Your rental total went down by ₱%s.', number_format($pricing->reduction)),
                default => 'Your rental total is unchanged.',
            },
            self::noteSuffix($note),
        ));
        $this->entityManager->flush();

        $this->auditLogger->log('early_return_activated', 'rental_adjustment', $adjustment->getId(), [
            'admin_id' => $admin->getId(),
            'new_return_at' => $pricing->requestedReturnAt->format('Y-m-d H:i:s'),
            'reduction' => $pricing->reduction,
            'refund' => $credit,
        ], $admin);
    }

    /**
     * Verified balance, extension and modification payments, less every refund
     * already paid out or under way against them.
     */
    private function refundableRentalPayments(Booking $booking): int
    {
        $available = 0;
        foreach ($this->payments->findForBooking($booking) as $payment) {
            if ($payment->isPaid() && \in_array($payment->getPaymentType(), self::RENTAL_PAYMENT_TYPES, true)) {
                $available += $this->refunds->refundableBalance($payment);
            }
        }

        return $available;
    }

    private function notifyCustomer(RentalAdjustmentRequest $adjustment, string $title, string $message): void
    {
        $booking = $adjustment->getBooking();
        $this->notifications->notifyUser($booking->getUser(), $title, $message, 'rental', $booking);
    }

    private static function noteSuffix(string $note): string
    {
        return '' === trim($note) ? '' : ' Rental desk note: '.mb_substr(trim($note), 0, 1500);
    }

    /**
     * v15 extension_pricing(): current daily rate, the booked add-ons and the
     * booking's promotion, compared with what the booking costs now.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function extensionPricing(Booking $booking, \DateTimeImmutable $requestedReturnAt): ExtensionPricing
    {
        if ($requestedReturnAt <= $booking->getPickupAt()) {
            throw new \InvalidArgumentException('The requested return must be after pickup.');
        }
        $days = max(1, (int) ceil(($requestedReturnAt->getTimestamp() - $booking->getPickupAt()->getTimestamp()) / 86400));
        if ($days > BookingCalculator::MAX_DAYS) {
            throw new \InvalidArgumentException('Online rentals can cover up to 30 days. Contact support for a longer arrangement.');
        }
        $vehicle = $booking->getVehicle();
        if (!$vehicle->isActive()) {
            throw new \DomainException('This vehicle is no longer eligible for an extension.');
        }

        $subtotal = (int) $vehicle->getPrice() * $days;
        $addonsTotal = 0;
        foreach ($booking->getAddons() as $line) {
            $addonsTotal += $line->getLineTotal();
        }
        // v15 keeps the booking's promotion even if it has since ended
        $promo = '' === $booking->getPromoCode() ? null : $this->promos->findOneBy(['code' => $booking->getPromoCode()]);
        $discount = $promo?->discountFor($subtotal) ?? 0;
        $total = $subtotal + $addonsTotal + $booking->getDeliveryFee() - $discount;

        return new ExtensionPricing($requestedReturnAt, $days, $subtotal, $addonsTotal, $discount, $total, max(0, $total - $booking->getTotal()));
    }

    /**
     * v15 activate_extension_request() after a verified payment: the extension
     * is checked and priced again, and must be paid in full. Runs inside the
     * caller's transaction (PaymentManager::review()).
     *
     * @param int $paid verified payments against this extension
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function activatePaidExtension(RentalAdjustmentRequest $adjustment, int $paid, User $admin): void
    {
        $booking = $adjustment->getBooking();
        if (!$adjustment->isExtension() || RentalAdjustmentRequest::STATUS_APPROVED !== $adjustment->getStatus() || 'active' !== $booking->getStatus()) {
            throw new \DomainException('This extension is not ready to activate.');
        }
        $this->entityManager->lock($booking->getVehicle(), LockMode::PESSIMISTIC_WRITE);
        $this->assertExtensionAvailable($booking, $adjustment->getRequestedReturnAt());
        $pricing = $this->extensionPricing($booking, $adjustment->getRequestedReturnAt());
        if ($paid < $pricing->priceDifference) {
            throw new \DomainException('Verify the full extension payment before activating the new return schedule.');
        }

        $this->activateExtension($adjustment, $pricing, $admin);
        $this->notifications->notifyUser(
            $booking->getUser(),
            'Rental extension activated',
            sprintf('Booking %s now has a return time of %s.', $booking->getReference(), $pricing->requestedReturnAt->format('M j, Y g:i A')),
            'rental',
            $booking,
        );
    }

    /**
     * v15 activate_extension_request(): the booking takes the later return.
     */
    private function activateExtension(RentalAdjustmentRequest $adjustment, ExtensionPricing $pricing, User $admin): void
    {
        $adjustment->getBooking()->extendTo($pricing->requestedReturnAt, $pricing->subtotal, $pricing->addonsTotal, $pricing->discount, $pricing->total);
        $adjustment->markActivated($pricing->priceDifference);
        $this->entityManager->flush();
        $this->auditLogger->log('extension_activated', 'rental_adjustment', $adjustment->getId(), ['admin_id' => $admin->getId(), 'new_return_at' => $pricing->requestedReturnAt->format('Y-m-d H:i:s')], $admin);
    }

    /**
     * v15 assert_extension_available().
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    private function assertExtensionAvailable(Booking $booking, \DateTimeImmutable $requestedReturnAt): void
    {
        $currentReturn = $booking->getReturnAt();
        if ($requestedReturnAt <= $currentReturn) {
            throw new \InvalidArgumentException('An extension must be later than the current scheduled return.');
        }
        if ($currentReturn <= new \DateTimeImmutable()) {
            throw new \DomainException('This rental is already due or overdue. Contact the rental desk for assistance.');
        }
        $vehicle = $booking->getVehicle();
        if (!$this->bookings->isVehicleAvailable($vehicle, $currentReturn, $requestedReturnAt, $booking)
            || $this->maintenance->hasConflict($vehicle, $currentReturn, $requestedReturnAt)) {
            throw new \DomainException('This vehicle is unavailable for the requested extension period.');
        }
        if (!$this->confirmer->licenseCovers($booking, $requestedReturnAt)) {
            throw new \DomainException('Your driver’s license expires before the requested return. Upload a renewed license before extending the rental.');
        }
    }

    private function parseReturn(string $date, string $time): \DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day || $day->format('Y-m-d') !== $date || !preg_match(self::TIME_PATTERN, $time)) {
            throw new \InvalidArgumentException('Choose a valid return date and time.');
        }

        return new \DateTimeImmutable($date.' '.$time);
    }
}
