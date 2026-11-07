<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\BookingCancellationRequest;
use App\Entity\User;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Service\AuditLogger;
use App\Service\Payment\PaymentLedger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 booking-management-service.php, cancellations: a customer asks to cancel
 * before pickup and an administrator approves or rejects it. Verified
 * payments are refundable in full more than 24 hours before pickup. (Refund
 * records arrive with the admin payments step, so the refund is estimated but
 * not yet recorded.)
 */
final class BookingCancellationManager
{
    use RunsInTransaction;

    // v15 CANCELLATION_REFUND_CUTOFF_HOURS
    public const REFUND_CUTOFF_HOURS = 24;
    public const DECISIONS = [BookingCancellationRequest::STATUS_APPROVED, BookingCancellationRequest::STATUS_REJECTED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingCancellationRequestRepository $cancellations,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly AuditLogger $auditLogger,
        private readonly PaymentLedger $ledger,
    ) {
    }

    public function estimate(Booking $booking): CancellationEstimate
    {
        // v15 cancellation_refund_preview(): verified payments (no refund records yet)
        $paid = $this->ledger->summary($booking)->paidTotal;
        $hours = (int) floor(($booking->getPickupAt()->getTimestamp() - time()) / 3600);
        $refundable = $hours > self::REFUND_CUTOFF_HOURS ? $paid : 0;

        return new CancellationEstimate($paid, $refundable, max(0, $paid - $refundable), $hours);
    }

    /**
     * v15 request_booking_cancellation().
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function request(Booking $booking, User $user, string $reason): BookingCancellationRequest
    {
        if (!$booking->isOwnedBy($user)) {
            throw new \DomainException('This booking does not belong to your account.');
        }
        if (!$booking->isPrePickup()) {
            throw new \DomainException('This booking can no longer use pre-pickup cancellation.');
        }
        if (null !== $this->modifications->findUnresolvedFor($booking)) {
            throw new \DomainException('Resolve the pending booking modification before requesting cancellation.');
        }
        if ($booking->getPickupAt() <= new \DateTimeImmutable()) {
            throw new \DomainException('Pre-pickup cancellation requests are no longer available after the scheduled pickup time. Contact support for assistance.');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new \InvalidArgumentException('Tell us briefly why you need to cancel this booking.');
        }
        if (true === $this->cancellations->findLatestFor($booking)?->isPending()) {
            throw new \DomainException('A cancellation request is already awaiting review.');
        }

        $estimate = $this->estimate($booking);
        $cancellation = new BookingCancellationRequest($booking, $user, $reason, $estimate->refundable, $estimate->nonRefundable);
        $this->entityManager->persist($cancellation);
        $this->entityManager->flush();
        $this->auditLogger->log('booking_cancellation_requested', 'booking_cancellation', $cancellation->getId(), ['booking_id' => $booking->getId()]);

        return $cancellation;
    }

    /**
     * v15 review_booking_cancellation(): the refund split is recalculated and,
     * on approval, the booking is cancelled and its dates are freed.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function review(BookingCancellationRequest $cancellation, string $decision, User $admin, string $note = ''): void
    {
        if (!\in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Choose approve or reject.');
        }

        $this->transactional(function () use ($cancellation, $decision, $admin, $note): void {
            $this->entityManager->refresh($cancellation, LockMode::PESSIMISTIC_WRITE);
            if (!$cancellation->isPending()) {
                throw new \DomainException('That cancellation request is no longer pending.');
            }
            $booking = $cancellation->getBooking();
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if (!$booking->isPrePickup()) {
                throw new \DomainException('The booking is no longer eligible for pre-pickup cancellation.');
            }

            $estimate = $this->estimate($booking);
            $cancellation->review($decision, $admin, $note, $estimate->refundable, $estimate->nonRefundable);
            if (BookingCancellationRequest::STATUS_APPROVED === $decision) {
                $booking->cancel();
            }
            $this->entityManager->flush();
        });

        $booking = $cancellation->getBooking();
        if (BookingCancellationRequest::STATUS_APPROVED === $decision) {
            $this->auditLogger->log('booking_cancelled', 'booking', $booking->getId(), ['cancellation_request_id' => $cancellation->getId()], $admin);
        }
        $this->auditLogger->log('booking_cancellation_'.$decision, 'booking_cancellation', $cancellation->getId(), ['booking_id' => $booking->getId()], $admin);
    }
}
