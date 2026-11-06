<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One add-on line of a booking (v15 booking_addons).
 */
#[ORM\Entity]
#[ORM\Table(name: 'booking_addons')]
class BookingAddon extends AddonLine
{
    #[ORM\ManyToOne(inversedBy: 'addons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    public function __construct(Booking $booking, Addon $addon, int $quantity)
    {
        parent::__construct($addon, $quantity);
        $this->booking = $booking;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }
}
