<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingModificationRequest>
 */
class BookingModificationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingModificationRequest::class);
    }

    /**
     * v15 unresolved_booking_modification(): the latest pending or approved request.
     */
    public function findUnresolvedFor(Booking $booking): ?BookingModificationRequest
    {
        return $this->createQueryBuilder('m')
            ->where('m.booking = :booking')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('booking', $booking)
            ->setParameter('statuses', BookingModificationRequest::UNRESOLVED_STATUSES)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * v15 approved_modification_for_booking(): the latest approved request that costs more and so waits for payment.
     */
    public function findAwaitingPaymentFor(Booking $booking): ?BookingModificationRequest
    {
        return $this->createQueryBuilder('m')
            ->where('m.booking = :booking')
            ->andWhere('m.status = :approved')
            ->andWhere('m.priceDifference > 0')
            ->setParameter('booking', $booking)
            ->setParameter('approved', BookingModificationRequest::STATUS_APPROVED)
            ->orderBy('m.reviewedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Approved modifications with a price increase, for the outstanding modification charge.
     *
     * @return list<BookingModificationRequest>
     */
    public function findAllAwaitingPaymentFor(Booking $booking): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.booking = :booking')
            ->andWhere('m.status = :approved')
            ->andWhere('m.priceDifference > 0')
            ->setParameter('booking', $booking)
            ->setParameter('approved', BookingModificationRequest::STATUS_APPROVED)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<BookingModificationRequest> newest first
     */
    public function findForBooking(Booking $booking): array
    {
        return $this->findBy(['booking' => $booking], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * v15 admin_booking_modifications('pending').
     *
     * @return list<BookingModificationRequest>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('b', 'u', 'v')
            ->join('m.booking', 'b')
            ->join('m.user', 'u')
            ->join('b.vehicle', 'v')
            ->where('m.status = :pending')
            ->setParameter('pending', BookingModificationRequest::STATUS_PENDING)
            ->orderBy('m.requestedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
