<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * v15 vehicle_available(): the vehicle is bookable and no pending,
     * confirmed, ready or active booking overlaps the period.
     */
    public function isVehicleAvailable(Vehicle $vehicle, \DateTimeImmutable $pickupAt, \DateTimeImmutable $returnAt, ?Booking $except = null): bool
    {
        if (!$vehicle->isActive() || \in_array($vehicle->getAvailabilityStatus(), ['maintenance', 'unavailable'], true)) {
            return false;
        }

        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.vehicle = :vehicle')
            ->andWhere('b.status IN (:blocking)')
            ->andWhere('b.pickupAt < :returnAt')
            ->andWhere('b.returnAt > :pickupAt')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('blocking', Booking::BLOCKING_STATUSES)
            ->setParameter('returnAt', $returnAt)
            ->setParameter('pickupAt', $pickupAt);

        if (null !== $except) {
            $qb->andWhere('b <> :except')->setParameter('except', $except);
        }

        return 0 === (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function referenceExists(string $reference): bool
    {
        return $this->count(['reference' => $reference]) > 0;
    }

    /**
     * v15 bookings_for_user(): newest first, with the vehicle loaded.
     *
     * @return list<Booking>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('v')
            ->join('b.vehicle', 'v')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * v15 admin_rentals(): bookings the rental desk acts on, active first, then
     * ready, then returned, each by pickup time.
     *
     * @return list<Booking>
     */
    public function findForRentalDesk(): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('v', 'u')
            ->addSelect("CASE b.status WHEN 'active' THEN 0 WHEN 'ready' THEN 1 ELSE 2 END AS HIDDEN queue")
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('b.status IN (:statuses)')
            ->setParameter('statuses', ['ready', 'active', 'returned'])
            ->orderBy('queue', 'ASC')
            ->addOrderBy('b.pickupAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Confirmed bookings whose vehicle is being prepared, soonest pickup first.
     *
     * @return list<Booking>
     */
    public function findAwaitingPreparation(): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('v', 'u')
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('b.status = :confirmed')
            ->setParameter('confirmed', 'confirmed')
            ->orderBy('b.pickupAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active rentals of the vehicle, optionally leaving one booking out.
     */
    public function countActiveFor(Vehicle $vehicle, ?Booking $except = null): int
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.vehicle = :vehicle')
            ->andWhere('b.status = :active')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('active', 'active');
        if (null !== $except) {
            $qb->andWhere('b <> :except')->setParameter('except', $except);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Bookings the administrator can still reject, cancel or mark as a
     * no-show: everything before checkout, soonest pickup first.
     *
     * @return list<Booking>
     */
    public function findForStatusControls(): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('v', 'u')
            ->join('b.vehicle', 'v')
            ->join('b.user', 'u')
            ->where('b.status IN (:statuses)')
            ->setParameter('statuses', Booking::PRE_PICKUP_STATUSES)
            ->orderBy('b.pickupAt', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pending bookings older than the hold, or whose pickup has come.
     *
     * @return list<Booking>
     */
    public function findPendingHoldExpired(\DateTimeImmutable $createdBefore, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :pending')
            ->andWhere('b.createdAt <= :createdBefore OR b.pickupAt <= :now')
            ->setParameter('pending', 'pending')
            ->setParameter('createdBefore', $createdBefore)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active rentals whose return passed before the given time and that were not flagged yet.
     *
     * @return list<Booking>
     */
    public function findOverdueNotYetNotified(\DateTimeImmutable $returnBefore): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :active')
            ->andWhere('b.returnAt < :returnBefore')
            ->andWhere('b.overdueNotifiedAt IS NULL')
            ->setParameter('active', 'active')
            ->setParameter('returnBefore', $returnBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * Confirmed or ready bookings whose pickup passed before the given time without checkout, not flagged yet.
     *
     * @return list<Booking>
     */
    public function findMissedPickupsNotYetNotified(\DateTimeImmutable $pickupBefore): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status IN (:statuses)')
            ->andWhere('b.pickupAt < :pickupBefore')
            ->andWhere('b.missedPickupNotifiedAt IS NULL')
            ->setParameter('statuses', ['confirmed', 'ready'])
            ->setParameter('pickupBefore', $pickupBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * Bookings still holding the vehicle that end after the given time.
     *
     * @return list<Booking>
     */
    public function findBlockingEndingAfter(Vehicle $vehicle, \DateTimeImmutable $after): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.vehicle = :vehicle')
            ->andWhere('b.status IN (:blocking)')
            ->andWhere('b.returnAt > :after')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('blocking', Booking::BLOCKING_STATUSES)
            ->setParameter('after', $after)
            ->orderBy('b.pickupAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * v15 sync_vehicle_status(): a confirmed or ready booking not yet over.
     */
    public function hasUpcomingReservation(Vehicle $vehicle): bool
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.vehicle = :vehicle')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.returnAt >= :now')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('statuses', ['confirmed', 'ready'])
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
