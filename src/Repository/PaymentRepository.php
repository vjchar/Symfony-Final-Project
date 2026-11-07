<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use App\Entity\Payment;
use App\Entity\RentalAdjustmentRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * v15 payments_for_booking(): newest first.
     *
     * @return list<Payment>
     */
    public function findForBooking(Booking $booking): array
    {
        return $this->findBy(['booking' => $booking], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Pending and paid amounts already submitted against one approved extension.
     */
    public function sumSubmittedForAdjustment(RentalAdjustmentRequest $adjustment): int
    {
        return $this->sumWhere('p.rentalAdjustment = :request AND p.paymentType = :type', $adjustment, Payment::TYPE_EXTENSION);
    }

    /**
     * Pending and paid amounts already submitted against one approved modification.
     */
    public function sumSubmittedForModification(BookingModificationRequest $modification): int
    {
        return $this->sumWhere('p.bookingModification = :request AND p.paymentType = :type', $modification, Payment::TYPE_MODIFICATION);
    }

    /**
     * Verified payments against one extension.
     */
    public function sumPaidForAdjustment(RentalAdjustmentRequest $adjustment): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->where('p.rentalAdjustment = :request AND p.paymentType = :type AND p.status = :paid')
            ->setParameter('request', $adjustment)
            ->setParameter('type', Payment::TYPE_EXTENSION)
            ->setParameter('paid', Payment::STATUS_PAID)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * v15 admin_payments(): pending first, then newest, optionally one status only.
     *
     * @return list<Payment>
     */
    public function findForAdmin(string $status = 'all'): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('b', 'u', 'v')
            ->addSelect("CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN queue")
            ->join('p.booking', 'b')
            ->join('p.user', 'u')
            ->join('b.vehicle', 'v')
            ->orderBy('queue', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
        if ('all' !== $status) {
            $qb->where('p.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function sumPendingOfType(Booking $booking, string $type): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->where('p.booking = :booking AND p.paymentType = :type AND p.status = :pending')
            ->setParameter('booking', $booking)
            ->setParameter('type', $type)
            ->setParameter('pending', Payment::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function sumWhere(string $condition, object $request, string $type): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->where($condition)
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('request', $request)
            ->setParameter('type', $type)
            ->setParameter('statuses', [Payment::STATUS_PENDING, Payment::STATUS_PAID])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
