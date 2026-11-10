<?php

namespace App\Form\Model;

/**
 * Raw booking form input, kept as strings like v15's $_POST so that
 * BookingCalculator can apply the v15 rules and messages in their order.
 */
final class BookingRequest
{
    public const CHILD_SEAT = 'child-seat';

    public string $vehicle = '';
    public string $pickupDate = '';
    public string $pickupTime = '09:00';
    public string $returnDate = '';
    public string $returnTime = '09:00';
    public string $pickupMethod = 'Branch pickup';
    public string $location = '';
    public string $deliveryAddress = '';
    /** @var list<string> add-on keys */
    public array $addons = [];
    public string $childSeats = '1';
    public string $promo = '';
    public string $specialRequests = '';
    public bool $terms = false;

    public function hasAddon(string $key): bool
    {
        return \in_array($key, $this->addons, true);
    }
}
