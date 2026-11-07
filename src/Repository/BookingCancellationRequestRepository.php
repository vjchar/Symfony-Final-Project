<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\BookingCancellationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingCancellationRequest>
 */
class BookingCancellationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingCancellationRequest::class);
    }

    /**
     * v15 cancellation_request_for_booking(): the latest request, whatever its status.
     */
    public function findLatestFor(Booking $booking): ?BookingCancellationRequest
    {
        return $this->findOneBy(['booking' => $booking], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * v15 admin_cancellation_requests('pending').
     *
     * @return list<BookingCancellationRequest>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('b', 'u', 'v')
            ->join('c.booking', 'b')
            ->join('c.user', 'u')
            ->join('b.vehicle', 'v')
            ->where('c.status = :pending')
            ->setParameter('pending', BookingCancellationRequest::STATUS_PENDING)
            ->orderBy('c.requestedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
