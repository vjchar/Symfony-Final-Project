<?php

namespace App\Validator;

use App\Entity\Booking;
use App\Entity\Vehicle;
use App\Repository\BookingRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NoUpcomingBookingsWhenOfflineValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoUpcomingBookingsWhenOffline) {
            throw new UnexpectedTypeException($constraint, NoUpcomingBookingsWhenOffline::class);
        }
        if (!$value instanceof Vehicle || null === $value->getId()
            || ($value->isActive() && !\in_array($value->getAvailabilityStatus(), ['maintenance', 'unavailable'], true))) {
            return;
        }

        $conflicts = $this->bookings->findBlockingEndingAfter($value, new \DateTimeImmutable());
        if ([] === $conflicts) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ references }}', implode(', ', array_map(static fn (Booking $booking): string => $booking->getReference(), $conflicts)))
            ->atPath($value->isActive() ? 'availabilityStatus' : 'isActive')
            ->addViolation();
    }
}
