<?php

namespace App\Service\Booking;

/**
 * An active rental priced again up to an earlier return: the booking's own
 * daily rate for fewer started days, with its add-ons, delivery fee and
 * promotion kept.
 */
final readonly class EarlyReturnPricing
{
    public function __construct(
        public \DateTimeImmutable $requestedReturnAt,
        public int $days,
        public int $subtotal,
        public int $addonsTotal,
        public int $discount,
        public int $total,
        // never negative: how much less the rental now costs
        public int $reduction,
    ) {
    }
}
