<?php

namespace App\Service\Booking;

/**
 * v15 preview_booking_modification(): the requested booking priced, and
 * whether its vehicle is free for the new schedule.
 */
final readonly class ModificationPreview
{
    public function __construct(
        public BookingQuote $quote,
        // requested total minus the current booking total
        public int $difference,
        public bool $available,
    ) {
    }
}
