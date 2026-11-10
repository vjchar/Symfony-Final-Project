<?php

namespace App\Service\Rental;

use App\Entity\Booking;
use App\Entity\RentalSettlement;
use App\Repository\PaymentRefundRepository;
use App\Service\Payment\PaymentLedger;

/**
 * v15 refresh_rental_settlement_status(): after a deposit refund moves or a
 * return-charge payment is verified, the settlement's status is worked out
 * again. The caller flushes.
 */
final class SettlementRefresher
{
    public function __construct(
        private readonly PaymentRefundRepository $refunds,
        private readonly PaymentLedger $ledger,
    ) {
    }

    public function refresh(Booking $booking): ?RentalSettlement
    {
        $settlement = $booking->getSettlement();
        if (null === $settlement) {
            return null;
        }

        $deposit = $this->refunds->depositTotalsForBooking($booking);
        $settlement->refreshStatus($deposit['pending'], $deposit['refunded'], $this->ledger->summary($booking)->extraDue > 0);

        return $settlement;
    }
}
