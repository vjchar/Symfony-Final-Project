<?php

namespace App\Service\Rental;

/**
 * What one RentalSweeper run changed.
 */
final readonly class SweepResult
{
    public function __construct(
        public int $expiredBookings,
        public int $overdueRentals,
        public int $missedPickups,
        public int $documentsPurged = 0,
    ) {
    }
}
