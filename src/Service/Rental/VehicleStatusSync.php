<?php

namespace App\Service\Rental;

use App\Entity\Vehicle;
use App\Repository\BookingRepository;

/**
 * v15 sync_vehicle_status(): a vehicle's fleet state follows its bookings.
 * Rented while a rental is active, reserved while a confirmed or ready
 * booking is still ahead, otherwise available. Maintenance and a manual
 * "unavailable" are left alone. Run it after the booking change is flushed;
 * the caller flushes the vehicle.
 */
final class VehicleStatusSync
{
    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    public function sync(Vehicle $vehicle): void
    {
        if (!$vehicle->isActive()) {
            $vehicle->setAvailabilityStatus('unavailable');

            return;
        }
        if (\in_array($vehicle->getAvailabilityStatus(), ['maintenance', 'unavailable'], true)) {
            return;
        }

        $vehicle->setAvailabilityStatus(match (true) {
            $this->bookings->countActiveFor($vehicle) > 0 => 'rented',
            $this->bookings->hasUpcomingReservation($vehicle) => 'reserved',
            default => 'available',
        });
    }
}
