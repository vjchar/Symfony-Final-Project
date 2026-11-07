<?php

namespace App\Service\Rental;

/**
 * v15 rental_settlement_preview(): what the rental desk sees before it
 * breaks the return charge down.
 */
final readonly class SettlementPreview
{
    public function __construct(
        public int $depositHeld,
        public int $inspectionCharge,
    ) {
    }
}
