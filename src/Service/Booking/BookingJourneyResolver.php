<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\RentalSettlement;
use App\Repository\CustomerDocumentRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\ReviewRepository;
use App\Routing\PageUrlGenerator;
use App\Service\Document\DocumentChecklist;
use App\Service\Payment\PaymentLedger;
use App\Service\Payment\PaymentSummary;

/**
 * v15 booking_next_step(). A pending booking goes payment, then documents,
 * then verification, as in v15 resolve_pending_booking_journey(); once
 * confirmed the vehicle is being prepared, then it is ready for pickup or
 * delivery, then on the road. After the return the customer follows the
 * settlement: a balance to pay or a deposit refund on its way, and once
 * settled and completed, a review. A cancelled booking shows its refund.
 */
final class BookingJourneyResolver
{
    public function __construct(
        private readonly PageUrlGenerator $pageUrls,
        private readonly PaymentLedger $ledger,
        private readonly CustomerDocumentRepository $documents,
        private readonly ReviewRepository $reviews,
        private readonly PaymentRefundRepository $refunds,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly PaymentRepository $payments,
        private readonly BookingConfirmer $confirmer,
    ) {
    }

    public function resolve(Booking $booking): BookingJourney
    {
        $reference = ['reference' => $booking->getReference()];
        $bookingUrl = $this->pageUrls->generate('booking-view', $reference);
        $payments = $this->ledger->summary($booking);
        $documents = DocumentChecklist::fromDocuments($this->documents->findForUser($booking->getUser()));
        $journey = fn (string $stage, ?int $step, string $title, string $message, string $button, string $url, string $severity, bool $actionRequired = false, bool $noAction = false): BookingJourney => new BookingJourney(
            $stage, $step, $title, $message, $button, $url, $severity, $actionRequired, $noAction,
            $this->steps($booking, $stage, $step, $payments, $documents),
        );

        return match ($booking->getStatus()) {
            'pending' => $this->pending($journey, $booking, $payments, $documents, $reference, $bookingUrl),
            // v15 resolve_confirmed_booking_journey(): the same checks from current records, in case a payment or document changed since
            'confirmed' => $this->pending($journey, $booking, $payments, $documents, $reference, $bookingUrl, sprintf(
                'Your payment and required documents have been verified. We’ll notify you when the vehicle is ready for %s.',
                'Vehicle delivery' === $booking->getPickupMethod() ? 'delivery' : 'pickup',
            )),
            // v15 resolve_ready_booking_journey()
            'ready' => $booking->isDelivery()
                ? $journey('ready_delivery', 5, 'Ready for Delivery', 'Your vehicle has been prepared and is ready for the scheduled delivery.', 'View Delivery Details', $bookingUrl.'#pickup-details', 'success', actionRequired: true)
                : $journey('ready_pickup', 5, 'Ready for Pickup', 'Your vehicle has been prepared and is ready for pickup.', 'View Pickup Details', $bookingUrl.'#pickup-details', 'success', actionRequired: true),
            'active' => $this->active($journey, $booking, $payments, $reference, $bookingUrl),
            'returned' => $this->returned($journey, $booking, $payments, $bookingUrl),
            'completed' => $this->completed($journey, $booking, $payments, $reference, $bookingUrl),
            'cancelled' => $this->cancelled($journey, $booking, $bookingUrl),
            'rejected' => $this->closedWithRefunds($journey, $booking, $bookingUrl, 'Booking Rejected', 'This booking was not approved by the rental team.'),
            'no_show' => $journey('closed', null, 'Booking Closed — Not Collected', 'The vehicle was not collected at the scheduled pickup time, so this booking was closed. Contact the rental team about any payment questions.', 'View Booking', $bookingUrl, 'secondary', noAction: true),
            default => $journey('booking', 1, 'Booking Created', 'Your reservation has been saved.', 'View Booking', $bookingUrl, 'info'),
        };
    }

    /**
     * v15 customer_priority_booking_journey(): the booking whose next step matters most.
     *
     * @param list<Booking> $bookings
     *
     * @return array{booking: Booking, journey: BookingJourney}|null
     */
    public function priority(array $bookings): ?array
    {
        $best = null;
        foreach ($bookings as $booking) {
            $journey = $this->resolve($booking);
            if ($journey->noAction && !$journey->actionRequired) {
                continue;
            }
            if (null === $best || $journey->priority() > $best['journey']->priority()) {
                $best = ['booking' => $booking, 'journey' => $journey];
            }
        }

        return $best;
    }

    /**
     * v15 latest_actionable_booking_for_user(): the newest booking still before
     * pickup, otherwise the newest one on the road.
     *
     * @param list<Booking> $bookings newest first
     */
    public function latestActionable(array $bookings): ?Booking
    {
        foreach ([['pending', 'confirmed', 'ready'], ['active', 'returned']] as $statuses) {
            foreach ($bookings as $booking) {
                if (\in_array($booking->getStatus(), $statuses, true)) {
                    return $booking;
                }
            }
        }

        return null;
    }

    /**
     * v15 notification_journey_action(): where a notification's link goes.
     *
     * @return array{url: string, label: string}
     */
    public function notificationAction(Notification $notification): array
    {
        $title = mb_strtolower($notification->getTitle());
        $booking = $notification->getBooking();
        if (null !== $booking) {
            $reference = ['reference' => $booking->getReference()];
            $bookingUrl = $this->pageUrls->generate('booking-view', $reference);

            return match (true) {
                str_contains($title, 'booking created'), str_contains($title, 'payment required') => ['url' => $this->pageUrls->generate('payments', $reference), 'label' => 'Proceed to Payment'],
                str_contains($title, 'upload documents') => ['url' => $this->pageUrls->generate('documents', $reference), 'label' => 'Upload Documents'],
                str_contains($title, 'payment submitted') => ['url' => $bookingUrl.'#requirements', 'label' => 'View Current Step'],
                str_contains($title, 'payment update required'), str_contains($title, 'payment rejected'), str_contains($title, 'payment failed') => ['url' => $this->pageUrls->generate('payments', $reference), 'label' => 'Review Payment'],
                str_contains($title, 'document') && (str_contains($title, 'required') || str_contains($title, 'rejected') || str_contains($title, 'update')) => ['url' => $this->pageUrls->generate('documents', $reference), 'label' => 'Review Documents'],
                str_contains($title, 'ready for pickup') => ['url' => $bookingUrl.'#pickup-details', 'label' => 'View Pickup Details'],
                str_contains($title, 'ready for delivery') => ['url' => $bookingUrl.'#pickup-details', 'label' => 'View Delivery Details'],
                default => ['url' => $bookingUrl, 'label' => 'Open Booking'],
            };
        }

        return match ($notification->getType()) {
            'draft' => ['url' => $this->pageUrls->generate('saved-bookings'), 'label' => 'Open Saved Bookings'],
            'document' => ['url' => $this->pageUrls->generate('documents'), 'label' => 'Open Documents'],
            'payment' => ['url' => $this->pageUrls->generate('payments'), 'label' => 'Open Payments'],
            default => ['url' => $this->pageUrls->generate('notifications'), 'label' => 'View Update'],
        };
    }

    /**
     * v15 resolve_active_booking_journey().
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     * @param array{reference: string}                                                                       $reference
     */
    private function active(callable $journey, Booking $booking, PaymentSummary $payments, array $reference, string $bookingUrl): BookingJourney
    {
        $paymentUrl = $this->pageUrls->generate('payments', $reference);
        $adjustmentUrl = $this->pageUrls->generate('rental-adjustment', $reference);
        // bringing the vehicle back comes before any balance
        if ($booking->isOverdue()) {
            return $journey('overdue', null, 'Rental Overdue', sprintf(
                'This rental was due back on %s. Please return the vehicle or contact the rental desk; late returns are charged the daily rate of ₱%s for each started day.',
                $booking->getReturnAt()->format('M j, Y g:i A'),
                number_format($booking->getDailyRate()),
            ), 'View Active Rental', $bookingUrl, 'danger', actionRequired: true);
        }

        $owed = $payments->depositDue + $payments->rentalDue;
        if ($owed > 0) {
            return $journey('payment', null, 'Payment Balance Required', sprintf('A payment correction or remaining obligation requires ₱%s before the account is fully settled.', number_format($owed)), 'Review Payments', $paymentUrl, 'warning', actionRequired: true);
        }
        if ($payments->extensionDue > 0) {
            return $journey('extension_payment', null, 'Pay Extension Balance', 'Your approved extension has an outstanding balance before the new return schedule can activate.', 'Pay Extension Balance', $paymentUrl, 'warning', actionRequired: true);
        }

        $unresolved = $this->adjustments->findUnresolvedFor($booking);

        return match (true) {
            null !== $unresolved && !$unresolved->isExtension() => $journey('early_return_pending', null, 'Early Return Request Pending', 'Your request is waiting for the rental team. No action is required right now.', 'View Return Request', $adjustmentUrl, 'info', noAction: true),
            null !== $unresolved => $journey('extension_pending', null, 'Extension Request Pending', 'Your extension request is being reviewed. No action is required right now.', 'View Extension Request', $adjustmentUrl, 'info', noAction: true),
            default => $journey('active', null, 'Rental Active', 'Your rental is active. You can manage an early return or request an extension from the rental details.', 'View Active Rental', $bookingUrl, 'info'),
        };
    }

    /**
     * v15 resolve_returned_booking_journey().
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     */
    private function returned(callable $journey, Booking $booking, PaymentSummary $payments, string $bookingUrl): BookingJourney
    {
        $settlementUrl = $bookingUrl.'#settlement';
        $settlement = $booking->getSettlement();
        if (null === $settlement) {
            return $journey('settlement_pending', null, 'Return Settlement Pending', 'Your vehicle return is recorded. The rental team is finalizing the security-deposit settlement and any legitimate return deductions.', 'View Return Details', $settlementUrl, 'info', noAction: true);
        }
        if (null !== $balance = $this->returnBalance($journey, $booking, $payments, $settlement, 'Your security deposit was applied to the final return deductions. Complete the remaining %s balance to finish settlement.', 'Pay Outstanding Balance')) {
            return $balance;
        }
        if (RentalSettlement::STATUS_REFUND_PENDING === $settlement->getStatus()) {
            return $journey('deposit_refund_pending', null, 'Security Deposit Refund Pending', sprintf('Your return settlement is complete. A security-deposit refund of ₱%s is being handled by the rental team. No action is required right now.', number_format($settlement->getDepositRefundAmount())), 'View Settlement', $settlementUrl, 'info', noAction: true);
        }

        return $journey('returned', null, 'Rental Financially Settled', 'The return settlement is complete. No customer action is required while the team closes the rental.', 'View Settlement', $settlementUrl, 'success', noAction: true);
    }

    /**
     * v15 resolve_completed_booking_journey().
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     * @param array{reference: string}                                                                       $reference
     */
    private function completed(callable $journey, Booking $booking, PaymentSummary $payments, array $reference, string $bookingUrl): BookingJourney
    {
        $settlement = $booking->getSettlement();
        if (null !== $settlement) {
            if (null !== $balance = $this->returnBalance($journey, $booking, $payments, $settlement, 'A financial correction reopened an outstanding balance of %s. Complete it to restore final settlement.', 'Review Payments')) {
                return $balance;
            }
            if (RentalSettlement::STATUS_REFUND_PENDING === $settlement->getStatus()) {
                return $journey('deposit_refund_pending', null, 'Security Deposit Refund Pending', 'Your rental is returned, but the security-deposit refund is still being processed. No action is required right now.', 'View Settlement', $bookingUrl.'#settlement', 'info', noAction: true);
            }
        }

        return null === $this->reviews->findForBooking($booking)
            ? $journey('review', null, 'Rate Your Rental', 'Your rental is complete. Share your experience with a verified review.', 'Rate Your Rental', $this->pageUrls->generate('rate-trip', $reference), 'success', actionRequired: true)
            : $journey('complete', null, 'Rental Complete', 'Your rental and review process are complete.', 'View Booking', $bookingUrl, 'success', noAction: true);
    }

    /**
     * v15: a return balance still owed after settlement, or its payment waiting for verification.
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     */
    private function returnBalance(callable $journey, Booking $booking, PaymentSummary $payments, RentalSettlement $settlement, string $owedMessage, string $owedButton): ?BookingJourney
    {
        $owed = $payments->depositDue + $payments->rentalDue + $payments->extraDue;
        if ($owed <= 0 && RentalSettlement::STATUS_BALANCE_DUE !== $settlement->getStatus()) {
            return null;
        }
        $paymentUrl = $this->pageUrls->generate('payments', ['reference' => $booking->getReference()]);
        if ($this->payments->sumPendingOfType($booking, Payment::TYPE_EXTRA_CHARGE) > 0) {
            return $journey('return_payment_verification', null, 'Return Payment Verification', 'Your payment toward the outstanding return balance is awaiting administrator verification. No action is required right now.', 'View Payments', $paymentUrl, 'info', noAction: true);
        }

        return $journey('return_payment', null, 'Outstanding Return Balance', sprintf($owedMessage, '₱'.number_format($owed)), $owedButton, $paymentUrl, 'warning', actionRequired: true);
    }

    /**
     * v15 resolve_cancelled_booking_journey().
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     */
    private function cancelled(callable $journey, Booking $booking, string $bookingUrl): BookingJourney
    {
        return $this->closedWithRefunds($journey, $booking, $bookingUrl, 'Booking Cancelled', 'This booking is cancelled.');
    }

    /**
     * A cancelled or rejected booking: its refund, if any, is the only thing left.
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     */
    private function closedWithRefunds(callable $journey, Booking $booking, string $bookingUrl, string $title, string $sentence): BookingJourney
    {
        $refunds = $this->refunds->summaryForBooking($booking);

        return match (true) {
            $refunds->pending > 0 => $journey('refund_pending', null, 'Refund Pending', sprintf('%s A refund of ₱%s is awaiting processing.', $sentence, number_format($refunds->pending)), 'View Refund Details', $bookingUrl.'#refunds', 'info', noAction: true),
            $refunds->refunded > 0 => $journey('refund_processed', null, 'Refund Processed', sprintf('%s Recorded refunds total ₱%s.', $sentence, number_format($refunds->refunded)), 'View Refund History', $bookingUrl.'#refunds', 'success', noAction: true),
            default => $journey('closed', null, $title, $sentence.' It has no remaining customer action.', 'View Booking', $bookingUrl, 'secondary', noAction: true),
        };
    }

    /**
     * v15 resolve_pending_booking_journey().
     *
     * @param callable(string, ?int, string, string, string, string, string, bool=, bool=): BookingJourney $journey
     * @param array{reference: string}                                                                       $reference
     */
    private function pending(callable $journey, Booking $booking, PaymentSummary $payments, DocumentChecklist $documents, array $reference, string $bookingUrl, ?string $preparingMessage = null): BookingJourney
    {
        if (!$payments->depositSubmitted()) {
            $failed = $payments->depositFailed();

            return $journey(
                'payment', 2,
                $failed ? 'Payment Update Required' : 'Complete Payment',
                $failed ? 'Your previous security-deposit payment could not be verified. Please submit a new payment.' : 'Complete the required security-deposit payment to continue processing your reservation.',
                $failed ? 'Resubmit Payment' : 'Proceed to Payment',
                $this->pageUrls->generate('payments', $reference),
                $failed ? 'warning' : 'primary',
                actionRequired: true,
            );
        }

        $documentUrl = $this->pageUrls->generate('documents', $reference);
        if ($documents->needsReplacement()) {
            return $journey(
                'documents', 3, 'Document Requires Action',
                [] !== $documents->labels('expired') ? 'One of your required documents has expired. Replace it to continue.' : 'One of your required documents could not be verified. Replace the rejected document to continue.',
                'Replace Document', $documentUrl, 'warning', actionRequired: true,
            );
        }
        if (!$documents->allSubmitted()) {
            return $journey(
                'documents', 3, 'Upload Required Documents',
                sprintf('Next required document: %s. Upload the required documents while your payment is being verified.', $documents->labels('missing')[0]),
                'Upload Documents', $documentUrl, 'primary', actionRequired: true,
            );
        }
        if (!$this->confirmer->licenseCovers($booking, $booking->getReturnAt())) {
            return $journey('documents', 3, 'Renew Your Driver’s License', BookingConfirmer::LICENSE_TOO_SHORT, 'Replace Document', $documentUrl, 'warning', actionRequired: true);
        }
        if (!$payments->depositVerified() || !$documents->allApproved()) {
            return $journey(
                'verification', 4, 'Verification in Progress',
                'Only the requirements that are not yet verified are still under review. Completed document checks will not be repeated.',
                'View Verification Status', $bookingUrl.'#requirements', 'info', noAction: true,
            );
        }

        return $journey(
            'preparing', 5, 'We’re Preparing Your Vehicle',
            $preparingMessage ?? 'Your payment and valid required documents are already verified. No document step remains; the team is preparing your vehicle.',
            'View Booking', $bookingUrl, 'info', noAction: true,
        );
    }

    /**
     * v15 booking_journey_with_steps(): the five progress steps and their states.
     *
     * @return array<int, array{label: string, state: string}>
     */
    private function steps(Booking $booking, string $stage, ?int $current, PaymentSummary $payments, DocumentChecklist $documents): array
    {
        $paymentComplete = $payments->depositSubmitted();
        $documentsComplete = $documents->allSubmitted() && [] === $documents->labels('rejected');
        $preparing = 'preparing' === $stage || 'confirmed' === $booking->getStatus();
        $at = static fn (int $step): string => $step === $current ? 'current' : 'pending';

        $states = [
            1 => 'complete',
            2 => $paymentComplete ? 'complete' : ($payments->depositFailed() ? 'attention' : $at(2)),
            3 => $documents->needsReplacement() ? 'attention' : ($documentsComplete && $documents->allApproved() ? 'complete' : $at(3)),
            4 => $payments->depositVerified() && $documents->allApproved() ? 'complete' : $at(4),
            5 => \in_array($booking->getStatus(), ['ready', 'active', 'returned', 'completed'], true) ? 'complete' : ($preparing ? 'current' : 'pending'),
        ];
        $readyLabel = match (true) {
            $preparing => 'Preparing Vehicle',
            'ready' === $booking->getStatus() => 'Vehicle delivery' === $booking->getPickupMethod() ? 'Ready for Delivery' : 'Ready for Pickup',
            default => 'Ready',
        };

        return [
            1 => ['label' => 'Booking', 'state' => $states[1]],
            2 => ['label' => 'Payment', 'state' => $states[2]],
            3 => ['label' => 'Documents', 'state' => $states[3]],
            4 => ['label' => 'Verification', 'state' => $states[4]],
            5 => ['label' => $readyLabel, 'state' => $states[5]],
        ];
    }
}
