<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\Vehicle;
use App\Form\Model\BookingRequest;

/**
 * The booking details stored in a modification request's original_data and
 * requested_data, with the same keys as v15's booking_modification_original_payload()
 * and normalized_modification_payload(), so that "nothing changed" is a
 * plain comparison of the two arrays.
 */
final class ModificationPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromBooking(Booking $booking): array
    {
        $addons = [];
        foreach ($booking->getAddons() as $line) {
            $addons[(string) $line->getAddon()->getAddonKey()] = $line->getQuantity();
        }

        return self::payload(
            $booking->getVehicle(), $booking->getPickupAt(), $booking->getReturnAt(), $booking->getPickupMethod(),
            $booking->getPickupLocation(), $booking->getDeliveryAddress(), $addons, $booking->getPromoCode(),
            $booking->getSpecialRequests(),
            [$booking->getSubtotal(), $booking->getAddonsTotal(), $booking->getDeliveryFee(), $booking->getDiscount(), $booking->getTotal()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromQuote(BookingQuote $quote): array
    {
        $addons = [];
        foreach ($quote->addons as $line) {
            $addons[(string) $line->addon->getAddonKey()] = $line->quantity;
        }

        return self::payload(
            $quote->vehicle, $quote->pickupAt, $quote->returnAt, $quote->pickupMethod, $quote->pickupLocation,
            $quote->deliveryAddress, $addons, $quote->promoCode(), $quote->specialRequests,
            [$quote->subtotal, $quote->addonsTotal, $quote->deliveryFee, $quote->discount, $quote->total()],
        );
    }

    /**
     * The stored request as booking form input, to price it again at review.
     *
     * @param array<string, mixed> $payload
     */
    public static function toRequest(array $payload): BookingRequest
    {
        $request = new BookingRequest();
        $request->vehicle = (string) ($payload['vehicle'] ?? '');
        $request->pickupDate = (string) ($payload['pickup'] ?? '');
        $request->pickupTime = (string) ($payload['pickup_time'] ?? '');
        $request->returnDate = (string) ($payload['return'] ?? '');
        $request->returnTime = (string) ($payload['return_time'] ?? '');
        $request->pickupMethod = (string) ($payload['pickup_method'] ?? '');
        $request->location = (string) ($payload['location'] ?? '');
        $request->deliveryAddress = (string) ($payload['delivery_address'] ?? '');
        $request->addons = array_map('strval', (array) ($payload['addons'] ?? []));
        $request->childSeats = (string) ($payload['addon_quantities'][BookingRequest::CHILD_SEAT] ?? '1');
        $request->promo = (string) ($payload['promo'] ?? '');
        $request->specialRequests = (string) ($payload['special_requests'] ?? '');

        return $request;
    }

    /**
     * The booking as it stands, for the modification form.
     */
    public static function requestFromBooking(Booking $booking): BookingRequest
    {
        return self::toRequest(self::fromBooking($booking));
    }

    /**
     * @param array<string, int>              $addons add-on key => quantity
     * @param array{int, int, int, int, int} $amounts subtotal, add-ons, delivery, discount, total
     *
     * @return array<string, mixed>
     */
    private static function payload(
        Vehicle $vehicle,
        \DateTimeImmutable $pickupAt,
        \DateTimeImmutable $returnAt,
        string $pickupMethod,
        string $location,
        string $deliveryAddress,
        array $addons,
        string $promo,
        string $specialRequests,
        array $amounts,
    ): array {
        $quantities = [];
        if (isset($addons[BookingRequest::CHILD_SEAT])) {
            $quantities[BookingRequest::CHILD_SEAT] = max(1, min(4, $addons[BookingRequest::CHILD_SEAT]));
        }

        return [
            'vehicle' => (string) $vehicle->getSlug(),
            'vehicle_id' => (int) $vehicle->getId(),
            'vehicle_name' => (string) $vehicle->getName(),
            'pickup' => $pickupAt->format('Y-m-d'),
            'return' => $returnAt->format('Y-m-d'),
            'pickup_time' => $pickupAt->format('H:i'),
            'return_time' => $returnAt->format('H:i'),
            'pickup_at' => $pickupAt->format('Y-m-d H:i:s'),
            'return_at' => $returnAt->format('Y-m-d H:i:s'),
            'pickup_method' => $pickupMethod,
            'location' => $location,
            'delivery_address' => $deliveryAddress,
            'addons' => array_keys($addons),
            'addon_quantities' => $quantities,
            'promo' => $promo,
            'special_requests' => $specialRequests,
            'subtotal' => $amounts[0],
            'addons_total' => $amounts[1],
            'delivery_fee' => $amounts[2],
            'discount' => $amounts[3],
            'total' => $amounts[4],
        ];
    }
}
