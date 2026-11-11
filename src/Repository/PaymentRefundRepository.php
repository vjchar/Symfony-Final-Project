<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Pagination\Page;
use App\Service\Payment\RefundSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentRefund>
 */
class PaymentRefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRefund::class);
    }

    /**
     * v15 refunds_for_booking(): newest first, with the payment loaded.
     *
     * @return list<PaymentRefund>
     */
    public function findForBooking(Booking $booking): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p')
            ->join('r.payment', 'p')
            ->where('r.booking = :booking')
            ->setParameter('booking', $booking)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Refunds that already claim part of a payment (open or paid out),
     * optionally leaving one refund out, as v15 review_refund() does.
     */
    public function committedAmountForPayment(Payment $payment, ?PaymentRefund $except = null): int
    {
        $query = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.amount), 0)')
            ->where('r.payment = :payment')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('payment', $payment)
            ->setParameter('statuses', PaymentRefund::COMMITTED_STATUSES);

        if (null !== $except?->getId()) {
            $query->andWhere('r.id <> :except')->setParameter('except', $except->getId());
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * v15 refundable_balance_for_payment(): what can still be refunded from a
     * verified payment; 0 for one that is not verified.
     */
    public function refundableBalance(Payment $payment): int
    {
        if (!$payment->isPaid()) {
            return 0;
        }

        return max(0, $payment->getAmount() - $this->committedAmountForPayment($payment));
    }

    /**
     * The security-deposit refunds of a booking's settlement (only a return
     * settlement creates them), as v15 refresh_rental_settlement_status() totals them.
     *
     * @return array{pending: int, refunded: int}
     */
    public function depositTotalsForBooking(Booking $booking): array
    {
        $row = $this->createQueryBuilder('r')
            ->select(
                'COALESCE(SUM(CASE WHEN r.status IN (:open) THEN r.amount ELSE 0 END), 0) AS pending',
                'COALESCE(SUM(CASE WHEN r.status = :refunded THEN r.amount ELSE 0 END), 0) AS refunded',
            )
            ->where('r.booking = :booking')
            ->andWhere('r.refundType = :type')
            ->setParameter('open', PaymentRefund::OPEN_STATUSES)
            ->setParameter('refunded', PaymentRefund::STATUS_REFUNDED)
            ->setParameter('booking', $booking)
            ->setParameter('type', PaymentRefund::TYPE_SECURITY_DEPOSIT)
            ->getQuery()
            ->getSingleResult();

        return ['pending' => (int) $row['pending'], 'refunded' => (int) $row['refunded']];
    }

    public function summaryForBooking(Booking $booking): RefundSummary
    {
        return RefundSummary::of($this->findForBooking($booking));
    }

    /**
     * v15 refunds_for_user(): every refund on the customer's bookings.
     *
     * @return list<PaymentRefund>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('b', 'v')
            ->join('r.booking', 'b')
            ->join('b.vehicle', 'v')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * v15 admin_refunds(): open refunds first, then newest, filtered by status,
     * type and a search over booking, customer, vehicle and refund reference.
     *
     * @return Page<PaymentRefund>
     */
    public function findForAdmin(string $status = 'all', string $type = 'all', string $query = '', int $page = 1): Page
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('p', 'b', 'u', 'v')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 WHEN r.status = 'approved' THEN 1 WHEN r.status = 'processing' THEN 2 ELSE 3 END AS HIDDEN queue")
            ->join('r.payment', 'p')
            ->join('r.booking', 'b')
            ->join('b.user', 'u')
            ->join('b.vehicle', 'v')
            ->orderBy('queue', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');
        if ('all' !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ('all' !== $type) {
            $qb->andWhere('r.refundType = :type')->setParameter('type', $type);
        }
        if ('' !== $query) {
            $qb->andWhere('b.reference LIKE :term OR u.name LIKE :term OR u.email LIKE :term OR v.name LIKE :term OR r.referenceNumber LIKE :term')
                ->setParameter('term', '%'.addcslashes($query, '%_').'%');
        }

        return Page::of($qb, $page);
    }

    /**
     * v15 payments_for_booking() corrective_refunded_amount: per payment, the
     * paid-out corrections, modification credits and early-return credits,
     * which no longer count as paid.
     *
     * @return array<int, int> payment id => amount
     */
    public function correctiveRefundedPerPayment(Booking $booking): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.payment) AS payment', 'SUM(r.amount) AS amount')
            ->where('r.booking = :booking')
            ->andWhere('r.status = :refunded')
            ->andWhere('r.refundType IN (:types)')
            ->setParameter('booking', $booking)
            ->setParameter('refunded', PaymentRefund::STATUS_REFUNDED)
            ->setParameter('types', [PaymentRefund::TYPE_PAYMENT_CORRECTION, PaymentRefund::TYPE_BOOKING_MODIFICATION, PaymentRefund::TYPE_EARLY_RETURN])
            ->groupBy('r.payment')
            ->getQuery()
            ->getArrayResult();

        $amounts = [];
        foreach ($rows as $row) {
            $amounts[(int) $row['payment']] = (int) $row['amount'];
        }

        return $amounts;
    }

    public function countOpen(): int
    {
        return $this->count(['status' => PaymentRefund::OPEN_STATUSES]);
    }
}
