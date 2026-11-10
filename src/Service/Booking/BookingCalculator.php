<?php

namespace App\Service\Booking;

use App\Form\Model\BookingRequest;
use App\Repository\AddonRepository;
use App\Repository\PromoRepository;
use App\Repository\VehicleRepository;

/**
 * v15 parse_booking_input(): validates raw booking input and prices it on the
 * server. Rules run in v15's order and stop at the first problem with v15's
 * message, which callers show to the customer.
 */
final class BookingCalculator
{
    public const LOCATIONS = ['Manjuyod Branch', 'Bais City Branch', 'Dumaguete City Branch'];
    public const BRANCH_PICKUP = 'Branch pickup';
    public const DELIVERY = 'Vehicle delivery';
    public const PICKUP_METHODS = [self::BRANCH_PICKUP, self::DELIVERY];
    public const DELIVERY_FEE = 800;
    public const MAX_DAYS = 30;

    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly AddonRepository $addons,
        private readonly PromoRepository $promos,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with the v15 message for the first invalid field
     */
    public function quote(BookingRequest $request): BookingQuote
    {
        $vehicle = $this->vehicles->findOneBy(['slug' => trim($request->vehicle), 'isActive' => true]);
        if (null === $vehicle) {
            throw new \InvalidArgumentException('Choose an active vehicle.');
        }

        $pickupAt = $this->dateTime($request->pickupDate, $request->pickupTime);
        $returnAt = $this->dateTime($request->returnDate, $request->returnTime);
        if (null === $pickupAt || null === $returnAt) {
            throw new \InvalidArgumentException('Choose valid pick-up and return dates and times.');
        }
        if ($pickupAt <= new \DateTimeImmutable('+1 hour')) {
            throw new \InvalidArgumentException('Pick-up must be at least one hour from now.');
        }
        if ($returnAt <= $pickupAt) {
            throw new \InvalidArgumentException('Return must be later than pick-up.');
        }

        $days = max(1, (int) ceil(($returnAt->getTimestamp() - $pickupAt->getTimestamp()) / 86400));
        if ($days > self::MAX_DAYS) {
            throw new \InvalidArgumentException('Online bookings can cover up to 30 rental days. Contact support for longer rentals.');
        }

        $pickupMethod = trim($request->pickupMethod);
        if (!\in_array($pickupMethod, self::PICKUP_METHODS, true)) {
            throw new \InvalidArgumentException('Choose a valid pickup method.');
        }
        $location = trim($request->location);
        if (!\in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('Choose a valid branch.');
        }
        $deliveryAddress = trim($request->deliveryAddress);
        $isDelivery = self::DELIVERY === $pickupMethod;
        if ($isDelivery && (mb_strlen($deliveryAddress) < 8 || mb_strlen($deliveryAddress) > 255)) {
            throw new \InvalidArgumentException('Enter a complete delivery address.');
        }

        $addonLines = $this->addonLines($request);

        $subtotal = (int) $vehicle->getPrice() * $days;
        $promoCode = strtoupper(trim($request->promo));
        $promo = '' === $promoCode ? null : $this->promos->findRedeemable($promoCode, new \DateTimeImmutable());
        if ('' !== $promoCode && null === $promo) {
            throw new \InvalidArgumentException('The promotion code is invalid, inactive, expired, or fully used.');
        }

        return new BookingQuote(
            vehicle: $vehicle,
            pickupAt: $pickupAt,
            returnAt: $returnAt,
            days: $days,
            pickupMethod: $pickupMethod,
            pickupLocation: $location,
            deliveryAddress: $isDelivery ? $deliveryAddress : '',
            addons: $addonLines,
            promo: $promo,
            subtotal: $subtotal,
            addonsTotal: array_sum(array_map(static fn (QuotedAddon $line): int => $line->lineTotal, $addonLines)),
            deliveryFee: $isDelivery ? self::DELIVERY_FEE : 0,
            discount: $promo?->discountFor($subtotal) ?? 0,
            specialRequests: mb_substr(trim($request->specialRequests), 0, 2000),
        );
    }

    /**
     * Add-ons are charged once per booking; only child seats take a quantity (1-4).
     *
     * @return list<QuotedAddon>
     */
    private function addonLines(BookingRequest $request): array
    {
        $keys = array_values(array_unique(array_map('strval', $request->addons)));
        if ([] === $keys) {
            return [];
        }

        $selected = $this->addons->findBy(['addonKey' => $keys, 'isActive' => true], ['id' => 'ASC']);
        if (\count($selected) !== \count($keys)) {
            throw new \InvalidArgumentException('One or more selected add-ons are unavailable.');
        }

        $lines = [];
        foreach ($selected as $addon) {
            $quantity = 1;
            if (BookingRequest::CHILD_SEAT === $addon->getAddonKey()) {
                $quantity = filter_var($request->childSeats, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
                if (false === $quantity) {
                    throw new \InvalidArgumentException('Choose between 1 and 4 child safety seats.');
                }
            }
            $lines[] = new QuotedAddon($addon, $quantity);
        }

        return $lines;
    }

    private function dateTime(string $date, string $time): ?\DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day || $day->format('Y-m-d') !== $date || !preg_match(self::TIME_PATTERN, $time)) {
            return null;
        }

        return new \DateTimeImmutable($date.' '.$time);
    }
}
