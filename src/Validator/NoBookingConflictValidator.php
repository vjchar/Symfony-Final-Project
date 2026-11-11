<?php

namespace App\Validator;

use App\Entity\Booking;
use App\Entity\MaintenanceRecord;
use App\Repository\BookingRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NoBookingConflictValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoBookingConflict) {
            throw new UnexpectedTypeException($constraint, NoBookingConflict::class);
        }
        if (!$value instanceof MaintenanceRecord || null === $value->getVehicle() || null === $value->getStartsAt()
            || !\in_array($value->getStatus(), [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS], true)) {
            return;
        }

        // a maintenance record has no planned end, so it holds the vehicle from its start on;
        // work already in progress holds it from now even if it was planned for later
        $from = MaintenanceRecord::STATUS_IN_PROGRESS === $value->getStatus() ? min($value->getStartsAt(), new \DateTimeImmutable()) : $value->getStartsAt();
        $conflicts = $this->bookings->findBlockingEndingAfter($value->getVehicle(), $from);
        if ([] === $conflicts) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ references }}', implode(', ', array_map(static fn (Booking $booking): string => $booking->getReference(), $conflicts)))
            ->setParameter('{{ start }}', $from->format('M j, Y g:i A'))
            ->atPath('startsAt')
            ->addViolation();
    }
}
