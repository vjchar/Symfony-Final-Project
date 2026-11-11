<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * On a Vehicle: it cannot be deactivated or set to maintenance / unavailable
 * while bookings that are not over yet still hold it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NoUpcomingBookingsWhenOffline extends Constraint
{
    public string $message = 'This vehicle still has booking {{ references }}. Cancel or move it before taking the vehicle off the fleet.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
