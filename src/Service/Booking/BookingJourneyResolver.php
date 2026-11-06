<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Routing\PageUrlGenerator;

/**
 * v15 booking_next_step() for the statuses a booking can reach so far.
 * Payments and documents are not built yet, so a pending booking always asks
 * for the security-deposit payment; the later v15 stages (documents,
 * verification, modifications, refunds, reviews) come with those features.
 */
final class BookingJourneyResolver
{
    public function __construct(
        private readonly PageUrlGenerator $pageUrls,
    ) {
    }

    public function resolve(Booking $booking): BookingJourney
    {
        $reference = ['reference' => $booking->getReference()];
        $bookingUrl = $this->pageUrls->generate('booking-view', $reference);

        return match ($booking->getStatus()) {
            'pending' => $this->journey(
                $booking,
                stage: 'payment',
                stepIndex: 2,
                title: 'Complete Payment',
                message: 'Complete the required security-deposit payment to continue processing your reservation.',
                buttonLabel: 'Proceed to Payment',
                targetUrl: $this->pageUrls->generate('payments', $reference),
                severity: 'primary',
                actionRequired: true,
            ),
            'cancelled' => $this->closed($booking, $bookingUrl, 'Booking Cancelled', 'This booking is cancelled and has no remaining customer action.'),
            'rejected', 'no_show' => $this->closed($booking, $bookingUrl, 'Booking Closed', 'This booking has no remaining customer action.'),
            default => $this->journey(
                $booking,
                stage: 'booking',
                stepIndex: 1,
                title: 'Booking Created',
                message: 'Your reservation has been saved.',
                buttonLabel: 'View Booking',
                targetUrl: $bookingUrl,
                severity: 'info',
            ),
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

    private function closed(Booking $booking, string $bookingUrl, string $title, string $message): BookingJourney
    {
        return $this->journey($booking, 'closed', null, $title, $message, 'View Booking', $bookingUrl, 'secondary', noAction: true);
    }

    private function journey(
        Booking $booking,
        string $stage,
        ?int $stepIndex,
        string $title,
        string $message,
        string $buttonLabel,
        string $targetUrl,
        string $severity,
        bool $actionRequired = false,
        bool $noAction = false,
    ): BookingJourney {
        $state = static fn (int $step): string => match (true) {
            1 === $step => 'complete',
            $step === $stepIndex => 'current',
            default => 'pending',
        };

        return new BookingJourney($stage, $stepIndex, $title, $message, $buttonLabel, $targetUrl, $severity, $actionRequired, $noAction, [
            1 => ['label' => 'Booking', 'state' => $state(1)],
            2 => ['label' => 'Payment', 'state' => $state(2)],
            3 => ['label' => 'Documents', 'state' => $state(3)],
            4 => ['label' => 'Verification', 'state' => $state(4)],
            5 => ['label' => 'confirmed' === $booking->getStatus() ? 'Preparing Vehicle' : 'Ready', 'state' => $state(5)],
        ]);
    }
}
