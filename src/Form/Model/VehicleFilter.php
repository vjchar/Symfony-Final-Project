<?php

namespace App\Form\Model;

/**
 * Fleet search criteria from the v15 vehicles.php query string.
 * "All" / 0 mean "no filter", exactly like v15.
 */
final class VehicleFilter
{
    public const SORT_OPTIONS = [
        'Featured order' => 'featured',
        'Price: low to high' => 'price-asc',
        'Price: high to low' => 'price-desc',
        'Newest fleet entry' => 'newest',
        'Name A-Z' => 'name-asc',
    ];

    public const SEAT_OPTIONS = [
        'Any capacity' => 0,
        '4+ seats' => 4,
        '5+ seats' => 5,
        '7+ seats' => 7,
        '10+ seats' => 10,
        '15 seats' => 15,
    ];

    public const PRICE_OPTIONS = [
        'Any price' => 0,
        'Up to ₱5,000' => 5000,
        'Up to ₱7,500' => 7500,
        'Up to ₱10,000' => 10000,
        'Up to ₱20,000' => 20000,
        'Up to ₱30,000' => 30000,
    ];

    // v15 "operational states"; reserved and rented come from bookings.
    public const STATUS_OPTIONS = ['available', 'reserved', 'rented', 'maintenance'];

    public ?string $search = null;
    public string $category = 'All';
    public string $brand = 'All';
    public string $fuel = 'All';
    public int $seats = 0;
    public string $transmission = 'All';
    public string $status = 'All';
    public int $maxPrice = 0;
    public string $sort = 'featured';

    /**
     * Active filters as query parameters, without defaults (v15 $activeFilterParameters).
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'search' => trim((string) $this->search),
            'category' => $this->category,
            'brand' => $this->brand,
            'fuel' => $this->fuel,
            'seats' => $this->seats,
            'transmission' => $this->transmission,
            'status' => $this->status,
            'max_price' => $this->maxPrice,
            'sort' => $this->sort,
        ], static fn (string|int $value): bool => !\in_array($value, ['', 'All', 0, 'featured'], true));
    }
}
