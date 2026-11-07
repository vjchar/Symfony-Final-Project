<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\PromoRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Service\AuditLogger;
use App\Service\NotificationCenter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 rental-service.php, rental adjustments: while a rental is active the
 * customer may ask to return early or to extend it, and the rental desk
 * approves or rejects the request. An extension that costs nothing more is
 * activated on approval; one that costs more is activated once its payment
 * is verified (activatePaidExtension()). An approved early return is only
 * noted: the real return is recorded at check-in.
 */
final class RentalAdjustmentManager
{
    use RunsInTransaction;

    public const DECISIONS = ['approve', 'reject'];

    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly MaintenanceRecordRepository $maintenance,
        private readonly PromoRepository $promos,
        private readonly RentalAdjustmentRequestRepository $adjustments,
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
            // v15 counts from started_at, which the rental desk step records; until then, pick-up
            if ($requestedReturnAt <= $booking->getPickupAt() || $requestedReturnAt >= $booking->getReturnAt()) {
                throw new \InvalidArgumentException('Choose a return time after vehicle release and before the current scheduled return.');
            }
            if ($requestedReturnAt <= new \DateTimeImmutable()) {
                throw new \InvalidArgumentException('Choose a future early-return time.');
            }
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
     * priced again before it is approved.
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
            $this->entityManager->refresh($booking);
            if (!$adjustment->isPending() || 'active' !== $booking->getStatus()) {
                throw new \DomainException('This request is no longer eligible for review.');
            }

            if ('reject' === $decision) {
                $adjustment->reject($admin, $note);
                $this->entityManager->flush();

                return 'rental_adjustment_rejected';
            }

            if (!$adjustment->isExtension()) {
                $adjustment->approve($admin, $note);
                $this->entityManager->flush();

                return 'early_return_approved';
            }

            $this->entityManager->lock($booking->getVehicle(), LockMode::PESSIMISTIC_WRITE);
            $this->assertExtensionAvailable($booking, $adjustment->getRequestedReturnAt());
            $pricing = $this->extensionPricing($booking, $adjustment->getRequestedReturnAt());
            $adjustment->approve($admin, $note, $pricing->priceDifference);
            if (0 === $pricing->priceDifference) {
                $this->activateExtension($adjustment, $pricing, $admin);
            }
            $this->entityManager->flush();

            return 'rental_extension_approved';
        });

        $this->auditLogger->log($action, 'rental_adjustment', $adjustment->getId(), ['admin_id' => $admin->getId(), 'price_difference' => $adjustment->getPriceDifference()], $admin);
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
