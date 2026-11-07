<?php

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\PaymentRepository;
use App\Repository\RentalAdjustmentRequestRepository;

/**
 * Reads a booking's payments into what is paid and what is due (v15
 * booking_payment_summary()). Refund records and return settlements come
 * with the admin payments step, so for now no refund reduces a payment and
 * the return balance is only what was charged as an extra.
 */
final class PaymentLedger
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly BookingModificationRequestRepository $modifications,
    ) {
    }

    public function summary(Booking $booking): PaymentSummary
    {
        $payments = $this->payments->findForBooking($booking);
        $paid = array_fill_keys(Payment::TYPES, 0);
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
            $paid[$payment->getPaymentType()] = ($paid[$payment->getPaymentType()] ?? 0) + $payment->getAmount();
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

        return new PaymentSummary(
            payments: $payments,
            depositDue: max(0, $booking->getDeposit() - $paid[Payment::TYPE_DEPOSIT]),
            rentalDue: max(0, $booking->getTotal() - $paid[Payment::TYPE_BALANCE] - $paid[Payment::TYPE_EXTENSION] - $paid[Payment::TYPE_MODIFICATION]),
            extraDue: max(0, $extraCharged - $paid[Payment::TYPE_EXTRA_CHARGE]),
            extensionDue: max(0, $extensionCharges - $paid[Payment::TYPE_EXTENSION]),
            modificationDue: max(0, $modificationCharges - $paid[Payment::TYPE_MODIFICATION]),
            paidTotal: array_sum($paid),
        );
    }
}
