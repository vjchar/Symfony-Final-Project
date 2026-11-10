<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\RentalAdjustmentRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentalAdjustmentRequest>
 */
class RentalAdjustmentRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentalAdjustmentRequest::class);
    }

    /**
     * v15 unresolved_rental_adjustment(): the latest pending or approved request.
     */
    public function findUnresolvedFor(Booking $booking): ?RentalAdjustmentRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.booking = :booking')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('booking', $booking)
            ->setParameter('statuses', RentalAdjustmentRequest::UNRESOLVED_STATUSES)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * v15 approved_extension_for_booking(): the latest approved extension, which waits for payment.
     */
    public function findApprovedExtensionFor(Booking $booking): ?RentalAdjustmentRequest
    {
        return $this->findOneBy(
            ['booking' => $booking, 'requestType' => RentalAdjustmentRequest::TYPE_EXTENSION, 'status' => RentalAdjustmentRequest::STATUS_APPROVED],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /**
     * @return list<RentalAdjustmentRequest> every approved extension, for the outstanding extension charge
     */
    public function findApprovedExtensionsFor(Booking $booking): array
    {
        return $this->findBy(['booking' => $booking, 'requestType' => RentalAdjustmentRequest::TYPE_EXTENSION, 'status' => RentalAdjustmentRequest::STATUS_APPROVED]);
    }

    /**
     * @return list<RentalAdjustmentRequest> newest first
     */
    public function findForBooking(Booking $booking): array
    {
        return $this->findBy(['booking' => $booking], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * v15 admin_rental_adjustments(): pending requests first, then approved
     * ones that wait for payment, oldest first.
     *
     * @return list<RentalAdjustmentRequest>
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('b', 'u', 'v')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN queue")
            ->join('r.booking', 'b')
            ->join('r.user', 'u')
            ->join('b.vehicle', 'v')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', RentalAdjustmentRequest::UNRESOLVED_STATUSES)
            ->orderBy('queue', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
