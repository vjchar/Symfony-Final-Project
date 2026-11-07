<?php

namespace App\Service\Booking;

/**
 * v15 cancellation_refund_preview(): how much of what was paid comes back.
 */
final readonly class CancellationEstimate
{
    public function __construct(
        public int $paid,
        public int $refundable,
        public int $nonRefundable,
        public int $hoursToPickup,
    ) {
    }
}
