<?php

namespace App\Service\Payment;

use App\Entity\Payment;

/**
 * v15 booking_payment_summary(): what is still owed on a booking, counting
 * only verified (paid) payments.
 */
final readonly class PaymentSummary
{
    /**
     * @param list<Payment> $payments newest first
     */
    public function __construct(
        public array $payments,
        public int $depositDue,
        public int $rentalDue,
        public int $extraDue,
        public int $extensionDue,
        public int $modificationDue,
        public int $paidTotal,
    ) {
    }

    /**
     * Everything still owed on the booking, deposit included.
     */
    public function amountDue(): int
    {
        return $this->depositDue + $this->rentalDue + $this->extraDue + $this->extensionDue + $this->modificationDue;
    }

    /**
     * v15 booking_deposit_journey_state(): status of the latest deposit payment, or "missing".
     */
    public function latestDepositStatus(): string
    {
        foreach ($this->payments as $payment) {
            if (Payment::TYPE_DEPOSIT === $payment->getPaymentType()) {
                return $payment->getStatus();
            }
        }

        return 'missing';
    }

    public function depositVerified(): bool
    {
        return 0 === $this->depositDue;
    }

    public function depositSubmitted(): bool
    {
        return $this->depositVerified() || Payment::STATUS_PENDING === $this->latestDepositStatus();
    }

    public function depositFailed(): bool
    {
        return !$this->depositVerified() && Payment::STATUS_FAILED === $this->latestDepositStatus();
    }
}
