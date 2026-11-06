<?php

namespace App\Service\Booking;

use App\Entity\Addon;

final readonly class QuotedAddon
{
    public int $lineTotal;

    public function __construct(
        public Addon $addon,
        public int $quantity,
    ) {
        $this->lineTotal = (int) $addon->getPrice() * $quantity;
    }
}
