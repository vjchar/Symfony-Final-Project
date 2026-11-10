<?php

namespace App\Service\Booking;

/**
 * v15 extension_pricing(): the rental priced again up to the later return.
 */
final readonly class ExtensionPricing
{
    public function __construct(
        public \DateTimeImmutable $requestedReturnAt,
        public int $days,
        public int $subtotal,
        public int $addonsTotal,
        public int $discount,
        public int $total,
        // never negative: what the customer pays on top
        public int $priceDifference,
    ) {
    }
}
