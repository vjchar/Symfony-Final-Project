<?php

namespace App\Service\Admin;

use App\Entity\Booking;
use App\Entity\BookingCancellationRequest;
use App\Entity\BookingModificationRequest;
use App\Entity\Payment;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 admin_report_data() for one date range: bookings created in it, their
 * verified payments, the change-request pipeline and the most booked
 * vehicles. Refund and return-settlement figures come with those features.
 */
final class RentalReport
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     rows: list<array{booking: Booking, paid: int}>,
     *     status_counts: array<string, int>,
     *     booking_value: int,
     *     verified_revenue: int,
     *     deposits_collected: int,
     *     rental_revenue: int,
     *     pending_adjustments: int,
     *     activated_extension_value: int,
     *     pending_modifications: int,
     *     cancellation_count: int,
     *     top_vehicles: list<array{name: string, rentals: int, value: int}>,
     *     max_rentals: int,
     * }
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromAt = $from->setTime(0, 0);
        $toAt = $to->setTime(23, 59, 59);

        /** @var list<Booking> $bookings */
        $bookings = $this->entityManager->createQueryBuilder()
            ->select('b', 'v', 'u')
            ->from(Booking::class, 'b')
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('b.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $fromAt)
            ->setParameter('to', $toAt)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $paidPerBooking = [];
        foreach ($this->paidPayments(array_map(static fn (Booking $booking): ?int => $booking->getId(), $bookings)) as $row) {
            $paidPerBooking[(int) $row['booking']] = (int) $row['paid'];
        }
        $rows = [];
        $statusCounts = [];
        $bookingValue = 0;
        $verifiedRevenue = 0;
        foreach ($bookings as $booking) {
            $paid = $paidPerBooking[$booking->getId()] ?? 0;
            $rows[] = ['booking' => $booking, 'paid' => $paid];
            $statusCounts[$booking->getStatus()] = ($statusCounts[$booking->getStatus()] ?? 0) + 1;
            $bookingValue += $booking->getTotal();
            $verifiedRevenue += $paid;
        }

        $finance = $this->entityManager->createQueryBuilder()
            ->select(
                "COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.paymentType = 'deposit' THEN p.amount ELSE 0 END), 0) AS deposits",
                "COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.paymentType <> 'deposit' THEN p.amount ELSE 0 END), 0) AS rental",
            )
            ->from(Payment::class, 'p')
            ->where('p.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $fromAt)
            ->setParameter('to', $toAt)
            ->getQuery()
            ->getSingleResult();

        [$pendingAdjustments, $extensionValue] = $this->adjustmentSummary($fromAt, $toAt);
        $topVehicles = $this->topVehicles($fromAt, $toAt);

        return [
            'rows' => $rows,
            'status_counts' => $statusCounts,
            'booking_value' => $bookingValue,
            'verified_revenue' => $verifiedRevenue,
            'deposits_collected' => (int) $finance['deposits'],
            'rental_revenue' => (int) $finance['rental'],
            'pending_adjustments' => $pendingAdjustments,
            'activated_extension_value' => $extensionValue,
            'pending_modifications' => $this->countBetween(BookingModificationRequest::class, 'createdAt', $fromAt, $toAt, BookingModificationRequest::STATUS_PENDING),
            'cancellation_count' => $this->countBetween(BookingCancellationRequest::class, 'requestedAt', $fromAt, $toAt),
            'top_vehicles' => $topVehicles,
            'max_rentals' => max([1, ...array_column($topVehicles, 'rentals')]),
        ];
    }

    /**
     * v15 rental-report CSV columns.
     *
     * @param list<array{booking: Booking, paid: int}> $rows
     *
     * @return list<list<string|int>>
     */
    public function csvRows(array $rows): array
    {
        $lines = [['Reference', 'Customer', 'Vehicle', 'Pickup', 'Original Return', 'Current Return', 'Status', 'Booking Total', 'Verified Payments']];
        foreach ($rows as $row) {
            $booking = $row['booking'];
            $lines[] = [
                $booking->getReference(),
                (string) $booking->getUser()->getName(),
                (string) $booking->getVehicle()->getName(),
                $booking->getPickupAt()->format('Y-m-d H:i:s'),
                ($booking->getOriginalReturnAt() ?? $booking->getReturnAt())->format('Y-m-d H:i:s'),
                $booking->getReturnAt()->format('Y-m-d H:i:s'),
                $booking->getStatus(),
                $booking->getTotal(),
                $row['paid'],
            ];
        }

        return $lines;
    }

    /**
     * @param list<int|null> $bookingIds
     *
     * @return list<array{booking: int, paid: string}>
     */
    private function paidPayments(array $bookingIds): array
    {
        if ([] === $bookingIds) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.booking) AS booking', 'SUM(p.amount) AS paid')
            ->from(Payment::class, 'p')
            ->where('p.booking IN (:ids) AND p.status = :paid')
            ->setParameter('ids', $bookingIds)
            ->setParameter('paid', Payment::STATUS_PAID)
            ->groupBy('p.booking')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array{int, int} pending adjustment requests, and the value of activated extensions
     */
    private function adjustmentSummary(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.requestType AS type', 'r.status AS status', 'COUNT(r.id) AS total', 'COALESCE(SUM(r.priceDifference), 0) AS value')
            ->from(RentalAdjustmentRequest::class, 'r')
            ->where('r.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('r.requestType', 'r.status')
            ->getQuery()
            ->getArrayResult();

        $pending = 0;
        $extensionValue = 0;
        foreach ($rows as $row) {
            if (RentalAdjustmentRequest::STATUS_PENDING === $row['status']) {
                $pending += (int) $row['total'];
            }
            if (RentalAdjustmentRequest::TYPE_EXTENSION === $row['type'] && \in_array($row['status'], [RentalAdjustmentRequest::STATUS_ACTIVATED, RentalAdjustmentRequest::STATUS_COMPLETED], true)) {
                $extensionValue += (int) $row['value'];
            }
        }

        return [$pending, $extensionValue];
    }

    /**
     * @param class-string $entity
     */
    private function countBetween(string $entity, string $field, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $status = null): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entity, 'e')
            ->where(sprintf('e.%s BETWEEN :from AND :to', $field))
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        if (null !== $status) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * v15 admin_report_fleet_performance(): the eight most booked vehicles.
     *
     * @return list<array{name: string, rentals: int, value: int}>
     */
    private function topVehicles(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('v.name AS name', 'COUNT(b.id) AS rentals', 'COALESCE(SUM(b.total), 0) AS value')
            ->from(Vehicle::class, 'v')
            ->leftJoin(Booking::class, 'b', 'WITH', 'b.vehicle = v AND b.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('v.id')
            ->orderBy('rentals', 'DESC')
            ->addOrderBy('value', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => ['name' => (string) $row['name'], 'rentals' => (int) $row['rentals'], 'value' => (int) $row['value']], $rows);
    }
}
