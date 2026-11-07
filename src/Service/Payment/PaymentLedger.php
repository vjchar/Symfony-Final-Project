<?php

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\RentalSettlementRepository;

/**
 * Reads a booking's payments into what is paid and what is due (v15
 * booking_payment_summary()). A paid-out correction or modification credit no
 * longer counts as paid; cancellation and deposit refunds don't reopen a
 * balance. Once the return is settled, the return balance is the
 * settlement's outstanding balance.
 */
final class PaymentLedger
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly PaymentRefundRepository $refunds,
        private readonly RentalSettlementRepository $settlements,
    ) {
    }

    public function summary(Booking $booking): PaymentSummary
    {
        $payments = $this->payments->findForBooking($booking);
        $corrected = [] === $payments ? [] : $this->refunds->correctiveRefundedPerPayment($booking);
        $paid = array_fill_keys(Payment::TYPES, 0);
        $grossPaid = 0;
        $extraCharged = 0;
        $paidPerAdjustment = [];
        $paidPerModification = [];

        foreach ($payments as $payment) {
            if (Payment::TYPE_EXTRA_CHARGE === $payment->getPaymentType()) {
                $extraCharged += $payment->getAmount();
            }
            if (!$payment->isPaid()) {
                continue;
            }
            $grossPaid += $payment->getAmount();
            $amount = max(0, $payment->getAmount() - ($corrected[$payment->getId()] ?? 0));
            $paid[$payment->getPaymentType()] = ($paid[$payment->getPaymentType()] ?? 0) + $amount;
            if (null !== $adjustment = $payment->getRentalAdjustment()) {
                $paidPerAdjustment[$adjustment->getId()] = ($paidPerAdjustment[$adjustment->getId()] ?? 0) + $payment->getAmount();
            }
            if (null !== $modification = $payment->getBookingModification()) {
                $paidPerModification[$modification->getId()] = ($paidPerModification[$modification->getId()] ?? 0) + $payment->getAmount();
            }
        }

        $extensionCharges = 0;
        foreach ($this->adjustments->findApprovedExtensionsFor($booking) as $extension) {
            $extensionCharges += max(0, $extension->getPriceDifference() - ($paidPerAdjustment[$extension->getId()] ?? 0));
        }
        $modificationCharges = 0;
        foreach ($this->modifications->findAllAwaitingPaymentFor($booking) as $modification) {
            $modificationCharges += max(0, $modification->getPriceDifference() - ($paidPerModification[$modification->getId()] ?? 0));
        }
        $returnObligation = $this->settlements->findForBooking($booking)?->getOutstandingBalance() ?? $extraCharged;

        return new PaymentSummary(
            payments: $payments,
            depositDue: max(0, $booking->getDeposit() - $paid[Payment::TYPE_DEPOSIT]),
            rentalDue: max(0, $booking->getTotal() - $paid[Payment::TYPE_BALANCE] - $paid[Payment::TYPE_EXTENSION] - $paid[Payment::TYPE_MODIFICATION]),
            extraDue: max(0, $returnObligation - $paid[Payment::TYPE_EXTRA_CHARGE]),
            extensionDue: max(0, $extensionCharges - $paid[Payment::TYPE_EXTENSION]),
            modificationDue: max(0, $modificationCharges - $paid[Payment::TYPE_MODIFICATION]),
            paidTotal: array_sum($paid),
            grossPaid: $grossPaid,
        );
    }
}
