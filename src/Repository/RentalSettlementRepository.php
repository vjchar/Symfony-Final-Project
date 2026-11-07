<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\RentalSettlement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentalSettlement>
 */
class RentalSettlementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentalSettlement::class);
    }

    /**
     * v15 rental_settlement_for_booking()
     */
    public function findForBooking(Booking $booking): ?RentalSettlement
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.finalizedBy', 'u')
            ->where('s.booking = :booking')
            ->setParameter('booking', $booking)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
