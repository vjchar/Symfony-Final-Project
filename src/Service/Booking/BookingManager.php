<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\BookingDraft;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\PromoRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 create_booking(), persist_booking_details() and convert_booking_draft().
 * The vehicle row is locked while availability is re-checked, so two customers
 * can't book the same dates at once. (v15's customer and admin notifications
 * arrive with the notifications feature.)
 */
final class BookingManager
{
    use RunsInTransaction;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly PromoRepository $promos,
        private readonly BookingCalculator $calculator,
        private readonly BookingDraftManager $drafts,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws \DomainException when the vehicle was taken for part of the period
     */
    public function create(User $user, BookingQuote $quote): Booking
    {
        $booking = $this->transactional(fn (): Booking => $this->persist($user, $quote));
        $this->auditLogger->log('booking_created', 'booking', $booking->getId(), ['reference' => $booking->getReference()]);

        return $booking;
    }

    /**
     * Books a saved draft with current availability and pricing.
     *
     * @throws \InvalidArgumentException|\DomainException
     */
    public function convertDraft(User $user, BookingDraft $draft): Booking
    {
        $this->drafts->expireStaleFor($user);
        if (!$draft->isActive()) {
            throw new \DomainException('Only an active saved booking can be converted.');
        }
        if ($draft->isExpired()) {
            $draft->expire();
            $this->entityManager->flush();
            throw new \DomainException('This saved booking has expired. Start a new booking using the latest availability and pricing.');
        }

        $booking = $this->transactional(function () use ($user, $draft): Booking {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $booking = $this->persist($user, $this->calculator->quote($draft->toRequest()));
            $draft->markConverted($booking);
            $this->entityManager->flush();

            return $booking;
        });

        $this->auditLogger->log('booking_draft_converted', 'booking_draft', $draft->getId(), ['booking_id' => $booking->getId(), 'booking_reference' => $booking->getReference()]);
        $this->auditLogger->log('booking_created', 'booking', $booking->getId(), ['reference' => $booking->getReference(), 'source' => 'booking_draft', 'draft_id' => $draft->getId()]);

        return $booking;
    }

    private function persist(User $user, BookingQuote $quote): Booking
    {
        $this->entityManager->lock($quote->vehicle, LockMode::PESSIMISTIC_WRITE);
        if (!$this->bookings->isVehicleAvailable($quote->vehicle, $quote->pickupAt, $quote->returnAt)) {
            throw new \DomainException('That vehicle is already reserved for part of the selected period. Choose another vehicle or different dates.');
        }

        do {
            $reference = 'VJ-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        } while ($this->bookings->referenceExists($reference));

        $booking = new Booking($reference, $user, $quote);
        $this->entityManager->persist($booking);
        $this->entityManager->flush();

        // claimed in the same transaction: if the last use went to someone else, the booking is not saved
        if (null !== $quote->promo && !$this->promos->recordUse($quote->promo)) {
            throw new \DomainException('The promotion code is invalid, inactive, expired, or fully used.');
        }

        return $booking;
    }
}
