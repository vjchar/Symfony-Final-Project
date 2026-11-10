<?php

namespace App\Service\Payment;

use App\Entity\PaymentRefund;

/**
 * v15 refund_summary_for_booking(): what has gone back to the customer and
 * what is still on its way.
 */
final readonly class RefundSummary
{
    /**
     * @param list<PaymentRefund> $refunds newest first
     */
    public function __construct(
        public array $refunds,
        public int $refunded,
        public int $pending,
        public string $status,
    ) {
    }

    /**
     * @param list<PaymentRefund> $refunds newest first
     */
    public static function of(array $refunds): self
    {
        $refunded = 0;
        $pending = 0;
        foreach ($refunds as $refund) {
            if ($refund->isRefunded()) {
                $refunded += $refund->getAmount();
            } elseif ($refund->isOpen()) {
                $pending += $refund->getAmount();
            }
        }

        $status = match (true) {
            $pending > 0 => 'pending',
            $refunded > 0 => PaymentRefund::STATUS_REFUNDED,
            [] !== $refunds => $refunds[0]->getStatus(),
            default => 'not_required',
        };

        return new self($refunds, $refunded, $pending, $status);
    }

    /**
     * Refunded plus still open: the part of what was paid that is spoken for.
     */
    public function total(): int
    {
        return $this->refunded + $this->pending;
    }
}
