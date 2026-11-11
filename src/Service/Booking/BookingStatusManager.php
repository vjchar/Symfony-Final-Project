<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Repository\PromoRepository;
use App\Service\AuditLogger;
use App\Service\NotificationCenter;
use App\Service\Payment\PaymentLedger;
use App\Service\Payment\RefundManager;
use App\Service\Rental\VehicleStatusSync;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The booking status changes the rental team makes outside the customer's
 * own requests: rejecting a pending booking, cancelling one before pickup,
 * and marking an uncollected one as a no-show. A rejected or cancelled
 * booking refunds everything verified as paid; a no-show refunds nothing
 * automatically (the payments desk can still add a correction). An unpaid
 * pending booking also expires on its own once its hold runs out.
 */
final class BookingStatusManager
{
    use RunsInTransaction;

    // how long an unpaid pending booking holds its vehicle (never past pickup)
    public const UNPAID_HOLD = '24 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $payments,
        private readonly PaymentRefundRepository $refunds,
        private readonly PaymentLedger $ledger,
        private readonly RefundManager $refundManager,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly BookingCancellationRequestRepository $cancellations,
        private readonly PromoRepository $promos,
        private readonly VehicleStatusSync $vehicles,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    public function reject(Booking $booking, User $admin, string $reason): void
    {
        $reason = self::reason($reason, 'Give the customer a reason for rejecting this booking.');
        $refund = $this->transactional(function () use ($booking, $admin, $reason): int {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            $booking->reject($reason);

            return $this->close($booking, $admin, 'Booking rejected by the rental team.', 'Booking Rejected', sprintf('Booking %s was not approved. Reason: %s', $booking->getReference(), $reason));
        });

        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => 'pending', 'to' => 'rejected', ...AuditLogger::actor($admin), 'refund' => $refund], $admin);
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    public function cancel(Booking $booking, User $admin, string $reason): void
    {
        $reason = self::reason($reason, 'Give the customer a reason for cancelling this booking.');
        [$from, $refund] = $this->transactional(function () use ($booking, $admin, $reason): array {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            $from = $booking->getStatus();
            if (!$booking->isPrePickup()) {
                throw new \DomainException('Only a booking that has not been picked up yet can be cancelled.');
            }
            $booking->cancel($reason);

            return [$from, $this->close($booking, $admin, 'Booking cancelled by the rental team.', 'Booking Cancelled by VJ Car Rental', sprintf('The rental team cancelled booking %s. Reason: %s', $booking->getReference(), $reason))];
        });

        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => $from, 'to' => 'cancelled', ...AuditLogger::actor($admin), 'refund' => $refund], $admin);
    }

    /**
     * @throws \DomainException
     */
    public function markNoShow(Booking $booking, User $admin, string $note = ''): void
    {
        $from = $this->transactional(function () use ($booking, $note): string {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            $from = $booking->getStatus();
            $booking->markNoShow($note);
            $this->closeOpenRequests($booking, 'Booking closed as a no-show.', null);
            $this->entityManager->flush();
            $this->vehicles->sync($booking->getVehicle());
            $this->notifications->notifyUser($booking->getUser(), 'Booking Closed — Vehicle Not Collected', sprintf(
                'The vehicle for booking %s was not collected at the scheduled pickup time (%s), so the booking was closed. Contact the rental team about your payments.',
                $booking->getReference(),
                $booking->getPickupAt()->format('M j, Y g:i A'),
            ), 'booking', $booking);
            $this->entityManager->flush();

            return $from;
        });

        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => $from, 'to' => 'no_show', ...AuditLogger::actor($admin)], $admin);
    }

    /**
     * Cancels a pending booking whose hold ran out with nothing paid or
     * awaiting verification.
     *
     * @return bool true when the booking expired now
     */
    public function expireIfUnpaid(Booking $booking, \DateTimeImmutable $now): bool
    {
        $expired = $this->transactional(function () use ($booking, $now): bool {
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if ('pending' !== $booking->getStatus() || !self::holdEnded($booking, $now)) {
                return false;
            }
            foreach ($this->payments->findForBooking($booking) as $payment) {
                if ($payment->isPaid() || $payment->isPending()) {
                    return false;
                }
            }

            $booking->cancel('Expired: no payment within the booking hold.');
            $this->closeOpenRequests($booking, 'Booking expired before payment.', null);
            $this->promos->releaseUse($booking->getPromoCode());
            $this->entityManager->flush();
            $this->vehicles->sync($booking->getVehicle());
            $this->notifications->notifyUser($booking->getUser(), 'Booking Expired', sprintf(
                'Booking %s was released because the security deposit was not submitted within %s of booking (or before pickup). You are welcome to book again.',
                $booking->getReference(),
                self::UNPAID_HOLD,
            ), 'booking', $booking);
            $this->entityManager->flush();

            return true;
        });

        if ($expired) {
            $this->auditLogger->log('booking_expired', 'booking', $booking->getId(), ['from' => 'pending', 'to' => 'cancelled']);
        }

        return $expired;
    }

    public static function holdEnded(Booking $booking, \DateTimeImmutable $now): bool
    {
        return $booking->getCreatedAt()->modify('+'.self::UNPAID_HOLD) <= $now || $booking->getPickupAt() <= $now;
    }

    /**
     * Closes the open requests, refunds everything verified as paid and tells
     * the customer. Runs inside the caller's transaction with the booking locked.
     *
     * @return int the refund created
     */
    private function close(Booking $booking, User $admin, string $requestNote, string $title, string $message): int
    {
        $this->closeOpenRequests($booking, $requestNote, $admin);
        $this->promos->releaseUse($booking->getPromoCode());
        $this->entityManager->flush();
        $this->vehicles->sync($booking->getVehicle());

        // verified payments, less refunds already paid out or under way
        $refund = max(0, $this->ledger->summary($booking)->grossPaid - $this->refunds->summaryForBooking($booking)->total());
        $this->refundManager->allocate($booking, $refund, PaymentRefund::TYPE_CANCELLATION, $requestNote.' Full refund of booking '.$booking->getReference(), $admin);
        $this->notifications->notifyUser($booking->getUser(), $title, $message.' '.($refund > 0
            ? sprintf('A full refund of ₱%s is awaiting processing.', number_format($refund))
            : 'No payment was recorded, so no refund is due.'), 'cancellation', $booking);
        $this->entityManager->flush();

        return $refund;
    }

    private function closeOpenRequests(Booking $booking, string $note, ?User $admin): void
    {
        $this->modifications->findUnresolvedFor($booking)?->closeWithBooking();
        $this->cancellations->findLatestFor($booking)?->closeWithBooking($note, $admin);
    }

    private static function reason(string $reason, string $missing): string
    {
        $reason = mb_substr(trim($reason), 0, 2000);
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException($missing);
        }

        return $reason;
    }
}
