<?php

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\BookingCancellationRequest;
use App\Entity\BookingModificationRequest;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Booking\RunsInTransaction;
use App\Service\NotificationCenter;
use App\Service\Rental\SettlementRefresher;
use App\Service\Rental\VehicleStatusSync;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 payment-service.php, refunds: every refund is tied to one verified
 * payment and is never more than what is left on it. Cancellations, cheaper
 * modifications and return settlements create them automatically; an
 * administrator can add a manual correction and moves each one through
 * approved → processing → refunded.
 */
final class RefundManager
{
    use RunsInTransaction;

    // v15 create_refund_requests_for_booking(): which payments give back first
    private const PAYMENT_ORDER = [
        Payment::TYPE_BALANCE => 0,
        Payment::TYPE_MODIFICATION => 1,
        Payment::TYPE_EXTENSION => 2,
        Payment::TYPE_DEPOSIT => 3,
    ];

    private const AUDIT_ACTIONS = [
        PaymentRefund::TYPE_CANCELLATION => 'cancellation_refund_created',
        PaymentRefund::TYPE_PAYMENT_CORRECTION => 'payment_refund_created',
        PaymentRefund::TYPE_SECURITY_DEPOSIT => 'security_deposit_refund_created',
        PaymentRefund::TYPE_BOOKING_MODIFICATION => 'modification_refund_created',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRefundRepository $refunds,
        private readonly PaymentRepository $payments,
        private readonly PaymentLedger $ledger,
        private readonly SettlementRefresher $settlements,
        private readonly VehicleStatusSync $vehicles,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * v15 create_refund_requests_for_booking(): spreads one refund over the
     * booking's verified payments, balance first and deposit last. Runs inside
     * the caller's transaction.
     *
     * @param key-of<PaymentRefund::TYPE_LABELS> $refundType
     * @param list<string>|null                  $paymentTypes only refund from these payment types
     *
     * @return list<PaymentRefund>
     *
     * @throws \DomainException when the verified payments cannot cover the amount
     */
    public function allocate(
        Booking $booking,
        int $amount,
        string $refundType,
        string $reason,
        User $admin,
        ?BookingCancellationRequest $cancellation = null,
        ?BookingModificationRequest $modification = null,
        ?array $paymentTypes = null,
    ): array {
        if ($amount <= 0) {
            return [];
        }

        $remaining = $amount;
        $created = [];
        foreach ($this->refundablePayments($booking, $paymentTypes) as $payment) {
            if ($remaining <= 0) {
                break;
            }
            $available = $this->refunds->refundableBalance($payment);
            if ($available <= 0) {
                continue;
            }
            $share = min($remaining, $available);
            $refund = new PaymentRefund($payment, $refundType, $share, $reason, $cancellation, $modification);
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
            $created[] = $refund;
            $remaining -= $share;

            $this->auditLogger->log('refund_created', 'payment_refund', $refund->getId(), [
                'booking_id' => $booking->getId(),
                'payment_id' => $payment->getId(),
                'refund_type' => $refundType,
                'amount' => $share,
                'requested_by' => $admin->getId(),
            ], $admin);
            $this->auditLogger->log(self::AUDIT_ACTIONS[$refundType], 'payment_refund', $refund->getId(), ['booking_id' => $booking->getId(), 'amount' => $share], $admin);
        }
        if ($remaining > 0) {
            throw new \DomainException('The requested refund exceeds the remaining verified paid amount.');
        }

        $label = PaymentRefund::TYPE_LABELS[$refundType];
        $this->notifications->notifyUser(
            $booking->getUser(),
            $label.' Pending',
            sprintf('%s of %s for booking %s is awaiting administrator processing.', $label, self::money($amount), $booking->getReference()),
            'refund',
            $booking,
        );
        $this->entityManager->flush();

        return $created;
    }

    /**
     * v15 create_payment_refund(): an administrator refunds part of one
     * verified payment as a correction (duplicate, overpayment, …).
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function createCorrection(Payment $payment, string $amount, string $reason, string $adminNote, User $admin): PaymentRefund
    {
        $amountValue = filter_var(trim($amount), \FILTER_VALIDATE_INT);
        if (false === $amountValue) {
            throw new \InvalidArgumentException('Choose a verified payment and valid refund amount.');
        }
        if (mb_strlen(trim($reason)) < 4) {
            throw new \InvalidArgumentException('Provide a clear reason for the payment refund.');
        }

        $refund = $this->transactional(function () use ($payment, $amountValue, $reason, $adminNote): PaymentRefund {
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if (!$payment->isPaid()) {
                throw new \DomainException('Only a verified payment can be refunded.');
            }
            $available = $this->refunds->refundableBalance($payment);
            if ($amountValue < 1 || $amountValue > $available) {
                throw new \InvalidArgumentException(sprintf('Refund amount cannot exceed %s.', self::money($available)));
            }
            $duplicate = $this->refunds->findOneBy([
                'payment' => $payment,
                'refundType' => PaymentRefund::TYPE_PAYMENT_CORRECTION,
                'amount' => $amountValue,
                'reason' => mb_substr(trim($reason), 0, 2000),
                'status' => PaymentRefund::OPEN_STATUSES,
            ]);
            if (null !== $duplicate) {
                throw new \DomainException('An identical unresolved payment refund already exists.');
            }

            $refund = new PaymentRefund($payment, PaymentRefund::TYPE_PAYMENT_CORRECTION, $amountValue, $reason, adminNote: $adminNote);
            $this->entityManager->persist($refund);
            $booking = $payment->getBooking();
            $this->notifications->notifyUser(
                $booking->getUser(),
                'Payment Refund Pending',
                sprintf('A payment correction refund of %s for booking %s is awaiting processing.', self::money($amountValue), $booking->getReference()),
                'refund',
                $booking,
            );
            $this->entityManager->flush();

            return $refund;
        });

        $this->auditLogger->log('payment_refund_created', 'payment_refund', $refund->getId(), [
            'booking_id' => $payment->getBooking()->getId(),
            'payment_id' => $payment->getId(),
            'amount' => $amountValue,
            'admin_id' => $admin->getId(),
        ], $admin);

        return $refund;
    }

    /**
     * v15 review_refund(): one step along the refund workflow. A security
     * deposit refund updates its settlement; refunding a confirmed booking's
     * deposit as a correction sends the booking back to payment.
     *
     * @throws \DomainException with the v15 message
     */
    public function transition(PaymentRefund $refund, string $status, User $admin, string $note = '', string $referenceNumber = ''): void
    {
        $this->transactional(function () use ($refund, $status, $admin, $note, $referenceNumber): void {
            $this->entityManager->refresh($refund, LockMode::PESSIMISTIC_WRITE);
            $payment = $refund->getPayment();
            if (\in_array($status, PaymentRefund::COMMITTED_STATUSES, true)
                && $this->refunds->committedAmountForPayment($payment, $refund) + $refund->getAmount() > $payment->getAmount()) {
                throw new \DomainException('Refund total cannot exceed the verified payment amount.');
            }

            $refund->transitionTo($status, $admin, $note, $referenceNumber);
            $this->entityManager->flush();

            $booking = $refund->getBooking();
            if (PaymentRefund::TYPE_SECURITY_DEPOSIT === $refund->getRefundType()) {
                $this->settlements->refresh($booking);
            }
            if ($refund->isRefunded()
                && PaymentRefund::TYPE_PAYMENT_CORRECTION === $refund->getRefundType()
                && Payment::TYPE_DEPOSIT === $payment->getPaymentType()
                && \in_array($booking->getStatus(), ['confirmed', 'ready'], true)
                && $this->ledger->summary($booking)->depositDue > 0) {
                $booking->reopenForPayment();
                $this->entityManager->flush();
                $this->vehicles->sync($booking->getVehicle());
            }

            [$title, $message] = $this->statusMessage($refund);
            $this->notifications->notifyUser($booking->getUser(), $title, $message, 'refund', $booking);
            $this->entityManager->flush();
        });

        $this->auditLogger->log('refund_'.(PaymentRefund::STATUS_REFUNDED === $status ? 'processed' : $status), 'payment_refund', $refund->getId(), [
            'booking_id' => $refund->getBooking()->getId(),
            'refund_type' => $refund->getRefundType(),
            'amount' => $refund->getAmount(),
        ], $admin);
    }

    /**
     * @param list<string>|null $types
     *
     * @return list<Payment>
     */
    private function refundablePayments(Booking $booking, ?array $types): array
    {
        $payments = array_values(array_filter(
            $this->payments->findForBooking($booking),
            static fn (Payment $payment): bool => $payment->isPaid() && (null === $types || \in_array($payment->getPaymentType(), $types, true)),
        ));
        usort($payments, static fn (Payment $a, Payment $b): int => [self::PAYMENT_ORDER[$a->getPaymentType()] ?? 4, $b->getPaidAt(), $b->getId()]
            <=> [self::PAYMENT_ORDER[$b->getPaymentType()] ?? 4, $a->getPaidAt(), $a->getId()]);

        return $payments;
    }

    /**
     * @return array{string, string} the v15 notification title and message
     */
    private function statusMessage(PaymentRefund $refund): array
    {
        $label = $refund->getRefundTypeLabel();
        $lower = mb_strtolower($label);
        $reference = $refund->getBooking()->getReference();
        $amount = self::money($refund->getAmount());

        return match ($refund->getStatus()) {
            PaymentRefund::STATUS_APPROVED => [$label.' Approved', sprintf('%s of %s for booking %s was approved.', $label, $amount, $reference)],
            PaymentRefund::STATUS_PROCESSING => [$label.' Processing', sprintf('Your %s for booking %s is being processed.', $lower, $reference)],
            PaymentRefund::STATUS_REFUNDED => [
                PaymentRefund::TYPE_SECURITY_DEPOSIT === $refund->getRefundType() ? 'Security Deposit Refunded' : $label.' Processed',
                sprintf('Your %s of %s for booking %s has been processed.', $lower, $amount, $reference),
            ],
            PaymentRefund::STATUS_REJECTED => [$label.' Rejected', sprintf('The %s for booking %s was rejected.', $lower, $reference)],
            PaymentRefund::STATUS_FAILED => [$label.' Failed', sprintf('The refund for booking %s could not be completed and needs administrator follow-up.', $reference)],
            PaymentRefund::STATUS_CANCELLED => [$label.' Cancelled', sprintf('The refund for booking %s was cancelled.', $reference)],
            default => ['Refund Updated', 'Your refund status was updated.'],
        };
    }

    private static function money(int $amount): string
    {
        return '₱'.number_format($amount);
    }
}
