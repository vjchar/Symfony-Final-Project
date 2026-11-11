<?php

namespace App\Service\Rental;

use App\Entity\Booking;

/**
 * Late returns: after a one-hour grace period, every started 24 hours past
 * the scheduled return is charged at the booking's own daily rate. The rental
 * desk sees it pre-filled at check-in and settlement and can still change it.
 */
final class LateReturnPolicy
{
    public const GRACE_SECONDS = 3600;

    public function lateDays(Booking $booking, \DateTimeImmutable $returnedAt): int
    {
        $late = $returnedAt->getTimestamp() - $booking->getReturnAt()->getTimestamp();

        return $late <= self::GRACE_SECONDS ? 0 : (int) ceil($late / 86400);
    }

    public function lateFee(Booking $booking, \DateTimeImmutable $returnedAt): int
    {
        return $this->lateDays($booking, $returnedAt) * $booking->getDailyRate();
    }
}
