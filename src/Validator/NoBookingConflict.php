<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * On a MaintenanceRecord: scheduled or in-progress work must not start
 * while the vehicle still has a booking that ends after it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NoBookingConflict extends Constraint
{
    public string $message = 'Booking {{ references }} still uses this vehicle after {{ start }}. Move or cancel the booking, or schedule the service after it ends.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
