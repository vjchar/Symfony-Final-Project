<?php

namespace App\Service\Admin;

/**
 * One entry of the v15 rental calendar: a pickup, delivery, return, active
 * rental, maintenance window or rental adjustment.
 */
final readonly class CalendarEvent
{
    public function __construct(
        public string $key,
        public string $type,
        public string $title,
        public \DateTimeImmutable $startAt,
        public string $vehicleName,
        public ?string $bookingReference,
        public ?string $customerName,
        public string $status,
        public string $meta,
        public string $url,
    ) {
    }

    public function day(): string
    {
        return $this->startAt->format('Y-m-d');
    }
}
