<?php

namespace App\Service\Rental;

use App\Entity\RentalInspection;

/**
 * The values the rental desk types into the v15 checkout / check-in form.
 */
final readonly class InspectionReading
{
    public function __construct(
        public int $odometer,
        public int $fuelPercent,
        public string $bodyCondition,
        public string $notes,
        public string $damageNotes,
        public int $extraCharges,
    ) {
    }

    /**
     * v15 save_inspection(): whole numbers in range, or one message for all.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromInput(string $odometer, string $fuelPercent, string $bodyCondition, string $notes, string $damageNotes, string $extraCharges = '0'): self
    {
        $integer = static fn (string $value, int $max): int|false => filter_var(trim($value), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        $odometerValue = $integer($odometer, RentalInspection::MAX_ODOMETER);
        $fuelValue = $integer($fuelPercent, 100);
        $extraValue = $integer('' === trim($extraCharges) ? '0' : $extraCharges, RentalInspection::MAX_EXTRA_CHARGES);
        $condition = trim($bodyCondition);

        if (false === $odometerValue || false === $fuelValue || false === $extraValue || !\in_array($condition, RentalInspection::CONDITIONS, true)) {
            throw new \InvalidArgumentException(RentalInspection::INVALID);
        }

        return new self($odometerValue, $fuelValue, $condition, $notes, $damageNotes, $extraValue);
    }
}
