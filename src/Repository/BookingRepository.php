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
}
