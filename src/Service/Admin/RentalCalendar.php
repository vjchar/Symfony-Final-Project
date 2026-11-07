<?php

namespace App\Service\Admin;

use App\Entity\Booking;
use App\Entity\MaintenanceRecord;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * v15 rental_calendar_events(): the operational schedule built from bookings,
 * maintenance records and rental adjustments. (The booking's real checkout
 * and check-in times come with the rental desk, so an active rental spans its
 * scheduled pickup and return.)
 */
final class RentalCalendar
{
    public const TYPES = [
        'pickup' => 'Pickup',
        'delivery' => 'Delivery',
        'return' => 'Return',
        'active' => 'Active rental',
        'maintenance' => 'Maintenance',
        'extension' => 'Extension',
        'early_return' => 'Expected early return',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<CalendarEvent> in schedule order
     */
    public function events(\DateTimeImmutable $start, \DateTimeImmutable $end, string $type = 'all', ?Vehicle $vehicle = null): array
    {
        $events = [];
        $wants = static fn (string $eventType): bool => 'all' === $type || $type === $eventType;

        foreach ($this->bookings($start, $end, $vehicle) as $booking) {
            $pickupType = 'Vehicle delivery' === $booking->getPickupMethod() ? 'delivery' : 'pickup';
            if ($wants($pickupType) && $this->within($booking->getPickupAt(), $start, $end)) {
                $events[] = $this->bookingEvent($booking, $pickupType, $booking->getPickupAt());
            }
            if ($wants('return') && $this->within($booking->getReturnAt(), $start, $end)) {
                $events[] = $this->bookingEvent($booking, 'return', $booking->getReturnAt());
            }
            if ($wants('active') && \in_array($booking->getStatus(), ['active', 'returned', 'completed'], true)) {
                $events[] = new CalendarEvent(
                    'active-'.$booking->getId(),
                    'active',
                    $booking->getVehicle()->getName().' — Active rental',
                    max($booking->getPickupAt(), $start),
                    (string) $booking->getVehicle()->getName(),
                    $booking->getReference(),
                    $booking->getUser()->getName(),
                    $booking->getStatus(),
                    sprintf('Active rental period · %s to %s', $booking->getPickupAt()->format('M j, Y g:i A'), $booking->getReturnAt()->format('M j, Y g:i A')),
                    $this->urls->generate('app_admin_rentals'),
                );
            }
        }

        if ($wants('maintenance')) {
            foreach ($this->maintenance($start, $end, $vehicle) as $record) {
                $startsAt = $record->getStartsAt();
                $description = trim((string) $record->getDescription());
                $events[] = new CalendarEvent(
                    'maintenance-'.$record->getId(),
                    'maintenance',
                    $record->getVehicle()?->getName().' — '.$record->getTitle(),
                    max($startsAt, $start),
                    (string) $record->getVehicle()?->getName(),
                    null,
                    null,
                    $record->getStatus(),
                    ('' !== $description ? $description.' · ' : '').'Scheduled '.$startsAt->format('M j, Y g:i A').(null !== $record->getEndsAt() ? ' to '.$record->getEndsAt()->format('M j, Y g:i A') : ''),
                    $this->urls->generate('app_admin_maintenance'),
                );
            }
        }

        if ($wants('extension') || $wants('early_return')) {
            foreach ($this->adjustments($start, $end, $vehicle) as $adjustment) {
                $eventType = $adjustment->isExtension() ? 'extension' : 'early_return';
                if (!$wants($eventType)) {
                    continue;
                }
                $booking = $adjustment->getBooking();
                $events[] = new CalendarEvent(
                    $eventType.'-'.$adjustment->getId(),
                    $eventType,
                    $booking->getVehicle()->getName().' — '.('extension' === $eventType ? 'Extended return' : 'Expected early return'),
                    $adjustment->getRequestedReturnAt(),
                    (string) $booking->getVehicle()->getName(),
                    $booking->getReference(),
                    $booking->getUser()->getName(),
                    $adjustment->getStatus(),
                    'extension' === $eventType ? 'Approved/current extension return' : 'Expected return only; actual check-in remains separate.',
                    $this->urls->generate('app_admin_rentals'),
                );
            }
        }

        usort($events, static fn (CalendarEvent $a, CalendarEvent $b): int => $a->startAt <=> $b->startAt);

        return $events;
    }

    private function within(\DateTimeImmutable $at, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $at >= $start && $at < $end;
    }

    private function bookingEvent(Booking $booking, string $type, \DateTimeImmutable $at): CalendarEvent
    {
        $labels = ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'return' => 'Scheduled return'];
        $place = 'Vehicle delivery' === $booking->getPickupMethod()
            ? ($booking->getDeliveryAddress() ?: 'Delivery address on booking')
            : $booking->getPickupLocation();

        return new CalendarEvent(
            $type.'-'.$booking->getId(),
            $type,
            $booking->getVehicle()->getName().' — '.$labels[$type],
            $at,
            (string) $booking->getVehicle()->getName(),
            $booking->getReference(),
            $booking->getUser()->getName(),
            $booking->getStatus(),
            $booking->getPickupMethod().' · '.$place,
            $this->urls->generate('app_admin_bookings'),
        );
    }

    /**
     * @return list<Booking> bookings that start, end or run within the range
     */
    private function bookings(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Vehicle $vehicle): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b', 'v', 'u')
            ->from(Booking::class, 'b')
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('b.status NOT IN (:closed)')
            ->andWhere('(b.pickupAt >= :start AND b.pickupAt < :end) OR (b.returnAt >= :start AND b.returnAt < :end) OR (b.pickupAt < :end AND b.returnAt > :start)')
            ->setParameter('closed', ['cancelled', 'rejected', 'no_show'])
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('b.pickupAt', 'ASC');
        if (null !== $vehicle) {
            $qb->andWhere('b.vehicle = :vehicle')->setParameter('vehicle', $vehicle);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<MaintenanceRecord> scheduled or in-progress work overlapping the range
     */
    private function maintenance(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Vehicle $vehicle): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m', 'v')
            ->from(MaintenanceRecord::class, 'm')
            ->join('m.vehicle', 'v')
            ->where('m.status IN (:open)')
            ->andWhere('m.startsAt < :end')
            ->andWhere('m.endsAt IS NULL OR m.endsAt >= :start')
            ->setParameter('open', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS])
            ->setParameter('start', $start)
            ->setParameter('end', $end);
        if (null !== $vehicle) {
            $qb->andWhere('m.vehicle = :vehicle')->setParameter('vehicle', $vehicle);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<RentalAdjustmentRequest> activated extensions and approved early returns in the range
     */
    private function adjustments(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Vehicle $vehicle): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r', 'b', 'v', 'u')
            ->from(RentalAdjustmentRequest::class, 'r')
            ->join('r.booking', 'b')
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('r.requestedReturnAt >= :start AND r.requestedReturnAt < :end')
            ->andWhere("(r.requestType = 'extension' AND r.status IN ('activated', 'completed')) OR (r.requestType = 'early_return' AND r.status IN ('approved', 'activated', 'completed'))")
            ->setParameter('start', $start)
            ->setParameter('end', $end);
        if (null !== $vehicle) {
            $qb->andWhere('b.vehicle = :vehicle')->setParameter('vehicle', $vehicle);
        }

        return $qb->getQuery()->getResult();
    }
}
