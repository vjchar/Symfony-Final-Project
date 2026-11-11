<?php

namespace App\Service\Rental;

use App\Entity\Booking;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The return charges the rental desk starts from: every km beyond the
 * vehicle's daily allowance for the billed days, and every started 10% of
 * fuel below the checkout level. Both rates come from the environment
 * (EXCESS_KM_RATE, REFUEL_RATE_PER_10_PERCENT); the desk can still change
 * the amounts before saving. A vehicle without a daily allowance has
 * unlimited mileage.
 */
final class ReturnChargeCalculator
{
    public function __construct(
        #[Autowire('%env(int:EXCESS_KM_RATE)%')] public readonly int $excessKmRate,
        #[Autowire('%env(int:REFUEL_RATE_PER_10_PERCENT)%')] public readonly int $refuelRate,
    ) {
    }

    /**
     * 0 when the vehicle has unlimited mileage.
     */
    public function kmAllowance(Booking $booking): int
    {
        return max(0, (int) $booking->getVehicle()->getDailyKm()) * $booking->getDays();
    }

    public function excessKm(Booking $booking, int $checkoutOdometer, int $returnOdometer): int
    {
        $allowance = $this->kmAllowance($booking);

        return 0 === $allowance ? 0 : max(0, $returnOdometer - $checkoutOdometer - $allowance);
    }

    public function mileageFee(Booking $booking, int $checkoutOdometer, int $returnOdometer): int
    {
        return $this->excessKm($booking, $checkoutOdometer, $returnOdometer) * $this->excessKmRate;
    }

    public function fuelFee(int $checkoutFuelPercent, int $returnFuelPercent): int
    {
        $missing = max(0, $checkoutFuelPercent - $returnFuelPercent);

        return (int) ceil($missing / 10) * $this->refuelRate;
    }
}
