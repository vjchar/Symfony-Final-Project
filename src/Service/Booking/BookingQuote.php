<?php

namespace App\Service\Booking;

use App\Entity\Promo;
use App\Entity\Vehicle;

/**
 * The server-authoritative result of v15 parse_booking_input(): validated trip
 * details and the price breakdown that bookings and drafts are created from.
 */
final readonly class BookingQuote
{
    /**
     * @param list<QuotedAddon> $addons
     */
    public function __construct(
        public Vehicle $vehicle,
        public \DateTimeImmutable $pickupAt,
        public \DateTimeImmutable $returnAt,
        public int $days,
        public string $pickupMethod,
        public string $pickupLocation,
        public string $deliveryAddress,
        public array $addons,
        public ?Promo $promo,
        public int $subtotal,
        public int $addonsTotal,
        public int $deliveryFee,
        public int $discount,
        public string $specialRequests,
    ) {
    }

    public function total(): int
    {
        return $this->subtotal + $this->addonsTotal + $this->deliveryFee - $this->discount;
    }

    public function deposit(): int
    {
        return (int) $this->vehicle->getDeposit();
    }

    public function promoCode(): string
    {
        return (string) $this->promo?->getCode();
    }
}
