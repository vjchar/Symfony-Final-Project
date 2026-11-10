<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Form\Model\BookingRequest;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Repository\MaintenanceRecordRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Payment\RefundManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 booking-management-service.php, modifications: a customer asks to change
 * a booking before pickup and an administrator approves or rejects it.
 * An approval that costs nothing more is applied at once; one that costs more
 * is applied once its payment is verified (activatePaid()). A cheaper
 * change gives the difference back from the verified rental payments.
 */
final class BookingModificationManager
{
    use RunsInTransaction;

    public const DECISIONS = [BookingModificationRequest::STATUS_APPROVED, BookingModificationRequest::STATUS_REJECTED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingCalculator $calculator,
        private readonly BookingRepository $bookings,
        private readonly MaintenanceRecordRepository $maintenance,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly BookingCancellationRequestRepository $cancellations,
        private readonly AuditLogger $auditLogger,
        private readonly PaymentRepository $payments,
        private readonly PaymentRefundRepository $refunds,
        private readonly RefundManager $refundManager,
    ) {
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    public function preview(Booking $booking, BookingRequest $request): ModificationPreview
    {
        if (!$booking->isPrePickup()) {
            throw new \DomainException('This booking can no longer be modified before pickup.');
        }
        $quote = $this->calculator->quote($request);
        $available = $this->bookings->isVehicleAvailable($quote->vehicle, $quote->pickupAt, $quote->returnAt, $booking)
            && !$this->maintenance->hasConflict($quote->vehicle, $quote->pickupAt, $quote->returnAt);

        return new ModificationPreview($quote, $quote->total() - $booking->getTotal(), $available);
    }

    /**
     * v15 request_booking_modification().
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function request(Booking $booking, User $user, BookingRequest $request, string $reason): BookingModificationRequest
    {
        if (!$booking->isOwnedBy($user)) {
            throw new \DomainException('This booking does not belong to your account.');
        }
        if (null !== $this->modifications->findUnresolvedFor($booking)) {
            throw new \DomainException('This booking already has a modification request awaiting resolution.');
        }
        if (true === $this->cancellations->findLatestFor($booking)?->isPending()) {
            throw new \DomainException('Resolve the pending cancellation request before changing this booking.');
        }
        $preview = $this->preview($booking, $request);
        if (!$preview->available) {
            throw new \DomainException('The requested vehicle or schedule is unavailable because of another reservation or maintenance period.');
        }
        $original = ModificationPayload::fromBooking($booking);
        $requested = ModificationPayload::fromQuote($preview->quote);
        if ($original === $requested) {
            throw new \InvalidArgumentException('Change at least one booking detail before submitting a modification request.');
        }

        $modification = new BookingModificationRequest($booking, $user, $original, $requested, $preview->quote->vehicle, $preview->difference, $reason);
        $this->entityManager->persist($modification);
        $this->entityManager->flush();
        $this->auditLogger->log('booking_modification_requested', 'booking_modification', $modification->getId(), ['booking_id' => $booking->getId()]);

        return $modification;
    }

    /**
     * v15 cancel_pending_booking_modification().
     *
     * @throws \DomainException
     */
    public function withdraw(BookingModificationRequest $modification, User $user): void
    {
        $modification->withdraw($user);
        $this->entityManager->flush();
        $this->auditLogger->log('booking_modification_cancelled', 'booking_modification', $modification->getId());
    }

    /**
     * v15 review_booking_modification(): the request is priced and checked
     * again with the latest bookings and maintenance before it is decided.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function review(BookingModificationRequest $modification, string $decision, User $admin, string $note = ''): void
    {
        if (!\in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Choose approve or reject.');
        }

        $difference = $this->transactional(function () use ($modification, $decision, $admin, $note): int {
            $this->entityManager->refresh($modification, LockMode::PESSIMISTIC_WRITE);
            if (!$modification->isPending()) {
                throw new \DomainException('That modification request is no longer pending.');
            }
            $booking = $modification->getBooking();
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
            if (!$booking->isPrePickup()) {
                throw new \DomainException('The booking is no longer eligible for pre-pickup modification.');
            }
            if (null !== $vehicle = $modification->getRequestedVehicle()) {
                $this->entityManager->lock($vehicle, LockMode::PESSIMISTIC_WRITE);
            }

            $preview = $this->preview($booking, ModificationPayload::toRequest($modification->getRequestedData()));
            if (!$preview->available) {
                throw new \DomainException('The requested vehicle or schedule is no longer available.');
            }

            if (BookingModificationRequest::STATUS_REJECTED === $decision) {
                $modification->reject($admin, $note, $preview->difference);
                $this->entityManager->flush();

                return $preview->difference;
            }

            $modification->approve($admin, $note, $preview->difference, ModificationPayload::fromQuote($preview->quote), $preview->quote->vehicle);
            if ($preview->difference <= 0) {
                $this->activate($modification, $preview->quote, $admin);
            }
            $this->entityManager->flush();

            return $preview->difference;
        });

        $bookingId = $modification->getBooking()->getId();
        if (BookingModificationRequest::STATUS_REJECTED === $decision) {
            $this->auditLogger->log('booking_modification_rejected', 'booking_modification', $modification->getId(), ['booking_id' => $bookingId], $admin);
        } else {
            $this->auditLogger->log('booking_modification_approved', 'booking_modification', $modification->getId(), ['booking_id' => $bookingId, 'price_difference' => $difference], $admin);
        }
    }

    /**
     * v15 activate_booking_modification() after a verified payment: the
     * approved changes are applied, if the vehicle and schedule are still free.
     * Runs inside the caller's transaction (PaymentManager::review()).
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function activatePaid(BookingModificationRequest $modification, User $admin): void
    {
        if (BookingModificationRequest::STATUS_APPROVED !== $modification->getStatus()) {
            throw new \DomainException('That booking modification is not awaiting activation.');
        }
        $booking = $modification->getBooking();
        $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);
        if (null !== $vehicle = $modification->getRequestedVehicle()) {
            $this->entityManager->lock($vehicle, LockMode::PESSIMISTIC_WRITE);
        }
        $preview = $this->preview($booking, ModificationPayload::toRequest($modification->getRequestedData()));
        if (!$preview->available) {
            throw new \DomainException('The requested vehicle or schedule is no longer available.');
        }

        $this->activate($modification, $preview->quote, $admin);
    }

    /**
     * v15 apply_booking_modification(): the booking takes the requested details.
     */
    private function activate(BookingModificationRequest $modification, BookingQuote $quote, User $admin): void
    {
        $booking = $modification->getBooking();
        $booking->reschedule($quote);
        $modification->markActivated();
        $this->entityManager->flush();

        // v15 modification_refundable_paid_amount(): verified balance and modification payments, less refunds already under way
        if ($modification->getPriceDifference() < 0) {
            $paid = 0;
            foreach ($this->payments->findForBooking($booking) as $payment) {
                if ($payment->isPaid() && \in_array($payment->getPaymentType(), [Payment::TYPE_BALANCE, Payment::TYPE_MODIFICATION], true)) {
                    $paid += $payment->getAmount();
                }
            }
            $credit = min(abs($modification->getPriceDifference()), max(0, $paid - $this->refunds->summaryForBooking($booking)->total()));
            $this->refundManager->allocate($booking, $credit, PaymentRefund::TYPE_BOOKING_MODIFICATION, 'Credit from approved booking modification', $admin, modification: $modification, paymentTypes: [Payment::TYPE_BALANCE, Payment::TYPE_MODIFICATION]);
        }

        $this->auditLogger->log('booking_modification_activated', 'booking_modification', $modification->getId(), ['booking_id' => $booking->getId()], $admin);
    }
}
