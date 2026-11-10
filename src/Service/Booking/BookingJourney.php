<?php

namespace App\Service\Booking;

/**
 * The single "what happens next" card v15 shows for a booking, with the
 * five-step progress bar (Booking, Payment, Documents, Verification, Ready).
 */
final readonly class BookingJourney
{
    /**
     * @param array<int, array{label: string, state: string}> $steps step number => label and complete|current|pending|attention
     */
    public function __construct(
        public string $stage,
        public ?int $stepIndex,
        public string $title,
        public string $message,
        public string $buttonLabel,
        public string $targetUrl,
        public string $severity,
        public bool $actionRequired,
        public bool $noAction,
        public array $steps,
    ) {
    }

    public function showsProgress(): bool
    {
        return null !== $this->stepIndex || \in_array($this->stage, ['preparing', 'ready_pickup', 'ready_delivery'], true);
    }

    public function icon(): string
    {
        return match ($this->stage) {
            'payment' => 'bi-credit-card',
            'documents' => 'bi-person-vcard',
            'verification' => 'bi-shield-check',
            'preparing' => 'bi-tools',
            'ready_pickup' => 'bi-key',
            'ready_delivery' => 'bi-truck',
            'active', 'early_return_pending', 'extension_pending' => 'bi-car-front',
            'extension_payment', 'return_payment', 'return_payment_verification' => 'bi-credit-card',
            'settlement_pending', 'returned' => 'bi-clipboard-check',
            'deposit_refund_pending', 'refund_pending', 'refund_processed' => 'bi-arrow-counterclockwise',
            'review' => 'bi-star',
            'complete' => 'bi-check2-circle',
            'closed' => 'bi-x-circle',
            default => 'bi-arrow-right-circle',
        };
    }

    /**
     * v15 booking_journey_priority(): the account dashboard shows the highest first.
     */
    public function priority(): int
    {
        return match ($this->stage) {
            'extension_payment' => 120,
            'payment' => 115,
            'documents' => 110,
            'ready_pickup', 'ready_delivery' => 105,
            'return_payment' => 100,
            'settlement_pending' => 98,
            'return_payment_verification' => 97,
            'deposit_refund_pending' => 96,
            'refund_pending' => 90,
            'verification' => 85,
            'preparing' => 80,
            'early_return_pending', 'extension_pending' => 75,
            'active' => 65,
            'review' => 55,
            'refund_processed' => 50,
            'returned' => 40,
            default => 10,
        };
    }
}
