<?php

namespace App\Service\Booking;

use App\Entity\BookingDraft;

/**
 * A saved draft priced again against today's availability, add-ons and promotions.
 */
final readonly class DraftPreview
{
    public int $currentTotal;
    public int $difference;

    public function __construct(
        public ?BookingQuote $quote,
        public bool $available,
        public string $error,
        public string $promoWarning,
        public int $savedTotal,
        public string $effectivePromoCode,
    ) {
        $this->currentTotal = $quote?->total() ?? 0;
        $this->difference = $this->currentTotal - $savedTotal;
    }

    public static function failed(BookingDraft $draft, string $error, string $promoWarning, string $promoCode): self
    {
        return new self(null, false, $error, $promoWarning, $draft->getEstimatedTotal(), $promoCode);
    }
}
