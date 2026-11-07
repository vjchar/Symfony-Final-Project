<?php

namespace App\Service\Rental;

use App\Entity\Booking;
use App\Entity\RentalSettlement;
use App\Repository\PaymentRefundRepository;
use App\Repository\RentalSettlementRepository;
use App\Service\Payment\PaymentLedger;

/**
 * v15 refresh_rental_settlement_status(): after a deposit refund moves or a
 * return-charge payment is verified, the settlement's status is worked out
 * again. The caller flushes.
 */
final class SettlementRefresher
{
    public function __construct(
        private readonly RentalSettlementRepository $settlements,
        private readonly PaymentRefundRepository $refunds,
        private readonly PaymentLedger $ledger,
    ) {
    }

    public function refresh(Booking $booking): ?RentalSettlement
    {
        $settlement = $this->settlements->findForBooking($booking);
        if (null === $settlement) {
            return null;
        }

        $deposit = $this->refunds->depositTotalsForSettlement($settlement);
        $settlement->refreshStatus($deposit['pending'], $deposit['refunded'], $this->ledger->summary($booking)->extraDue > 0);

        return $settlement;
    }
}
