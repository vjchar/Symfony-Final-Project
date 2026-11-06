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
            'payment' => 115,
            'documents' => 110,
            'verification' => 85,
            'preparing' => 80,
            default => 10,
        };
    }
}
