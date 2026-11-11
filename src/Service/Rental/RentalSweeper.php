<?php

namespace App\Service\Rental;

use App\Repository\BookingRepository;
use App\Service\AccountPrivacyManager;
use App\Service\Booking\BookingStatusManager;
use App\Service\NotificationCenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The time-driven side of the booking lifecycle, run every few minutes by
 * the scheduler (App\Schedule) or by hand with app:rentals:sweep:
 * - unpaid pending bookings whose hold ran out are released;
 * - active rentals past their return (plus the grace period) are flagged
 *   overdue once, to the customer and the rental desk;
 * - confirmed or ready bookings still not collected an hour after pickup are
 *   flagged once to the rental desk, who decide whether to mark a no-show;
 * - ID documents past their retention period are deleted.
 * Only one sweep runs at a time.
 */
final class RentalSweeper
{
    public const MISSED_PICKUP_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly BookingStatusManager $statuses,
        private readonly NotificationCenter $notifications,
        private readonly LateReturnPolicy $lateReturns,
        private readonly LockFactory $locks,
        private readonly AccountPrivacyManager $privacy,
    ) {
    }

    /**
     * @return SweepResult|null null when another sweep is already running
     */
    public function sweep(\DateTimeImmutable $now = new \DateTimeImmutable()): ?SweepResult
    {
        $lock = $this->locks->createLock('rental-sweep', 600);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return new SweepResult($this->expireUnpaid($now), $this->flagOverdue($now), $this->flagMissedPickups($now), $this->privacy->purgeExpiredDocuments($now));
        } finally {
            $lock->release();
        }
    }

    private function expireUnpaid(\DateTimeImmutable $now): int
    {
        $expired = 0;
        foreach ($this->bookings->findPendingHoldExpired($now->modify('-'.BookingStatusManager::UNPAID_HOLD), $now) as $booking) {
            if ($this->statuses->expireIfUnpaid($booking, $now)) {
                ++$expired;
            }
        }

        return $expired;
    }

    private function flagOverdue(\DateTimeImmutable $now): int
    {
        $rentals = $this->bookings->findOverdueNotYetNotified($now->modify(sprintf('-%d seconds', LateReturnPolicy::GRACE_SECONDS)));
        foreach ($rentals as $booking) {
            $booking->markOverdueNotified();
            $due = $booking->getReturnAt()->format('M j, Y g:i A');
            $this->notifications->notifyUser($booking->getUser(), 'Rental Overdue', sprintf(
                'Booking %s was due back on %s. Please return the vehicle as soon as possible or contact the rental desk. Late returns are charged ₱%s for each started day; ₱%s is due so far.',
                $booking->getReference(),
                $due,
                number_format($booking->getDailyRate()),
                number_format($this->lateReturns->lateFee($booking, $now)),
            ), 'rental', $booking);
            $this->notifications->notifyAdmins('Rental overdue', sprintf(
                '%s has not returned %s for booking %s, due %s.',
                $booking->getUser()->getName(),
                $booking->getVehicle()->getName(),
                $booking->getReference(),
                $due,
            ), 'rental', $booking);
            $this->entityManager->flush();
        }

        return \count($rentals);
    }

    private function flagMissedPickups(\DateTimeImmutable $now): int
    {
        $bookings = $this->bookings->findMissedPickupsNotYetNotified($now->modify(sprintf('-%d seconds', self::MISSED_PICKUP_AFTER_SECONDS)));
        foreach ($bookings as $booking) {
            $booking->markMissedPickupNotified();
            $this->notifications->notifyAdmins('Vehicle not collected', sprintf(
                'Booking %s (%s) was due for %s on %s but has not been checked out. Check out the vehicle or mark the booking as a no-show.',
                $booking->getReference(),
                $booking->getUser()->getName(),
                $booking->isDelivery() ? 'delivery' : 'pickup',
                $booking->getPickupAt()->format('M j, Y g:i A'),
            ), 'booking', $booking);
            $this->entityManager->flush();
        }

        return \count($bookings);
    }
}
