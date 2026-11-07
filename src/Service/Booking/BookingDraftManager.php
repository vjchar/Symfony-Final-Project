<?php

namespace App\Service\Booking;

use App\Entity\BookingDraft;
use App\Entity\User;
use App\Repository\BookingDraftRepository;
use App\Repository\BookingRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 saved booking drafts: save, expire after 7 days, discard, and re-check
 * a draft against today's availability and prices. Drafts never hold the
 * vehicle and never use up a promotion.
 */
final class BookingDraftManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingDraftRepository $drafts,
        private readonly BookingRepository $bookings,
        private readonly BookingCalculator $calculator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * v15 save_booking_draft(): creates a draft, or updates $draft when given.
     *
     * @throws \DomainException
     */
    public function save(User $user, BookingQuote $quote, ?BookingDraft $draft = null): BookingDraft
    {
        $this->expireStaleFor($user);
        if (!$this->bookings->isVehicleAvailable($quote->vehicle, $quote->pickupAt, $quote->returnAt)) {
            throw new \DomainException('This vehicle is already reserved during part of the selected schedule. Choose different dates or another vehicle before saving.');
        }

        if (null === $draft) {
            do {
                $reference = 'DRAFT-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            } while ($this->drafts->referenceExists($reference));

            $draft = new BookingDraft($reference, $user, $quote);
            $this->entityManager->persist($draft);
            $this->entityManager->flush();
            $this->auditLogger->log('booking_draft_created', 'booking_draft', $draft->getId(), ['reference' => $draft->getReference()]);

            return $draft;
        }

        if (!$draft->isActive() || $draft->isExpired()) {
            throw new \DomainException('This saved booking has expired or is no longer editable.');
        }

        $this->entityManager->wrapInTransaction(function () use ($draft, $quote): void {
            // the old lines go first: a draft holds each add-on once
            $draft->removeAddons();
            $this->entityManager->flush();
            $draft->applyQuote($quote);
            $this->entityManager->flush();
        });
        $this->auditLogger->log('booking_draft_updated', 'booking_draft', $draft->getId(), ['reference' => $draft->getReference()]);

        return $draft;
    }

    /**
     * @throws \DomainException
     */
    public function discard(BookingDraft $draft): void
    {
        if (!$draft->isActive()) {
            throw new \DomainException('This saved booking is no longer available to discard.');
        }

        $draft->discard();
        $this->entityManager->flush();
        $this->auditLogger->log('booking_draft_discarded', 'booking_draft', $draft->getId());
    }

    /**
     * v15 expire_booking_drafts_for_user().
     */
    public function expireStaleFor(User $user): void
    {
        $stale = $this->drafts->findStaleFor($user, new \DateTimeImmutable());
        if ([] === $stale) {
            return;
        }

        foreach ($stale as $draft) {
            $draft->expire();
        }
        $this->entityManager->flush();
        foreach ($stale as $draft) {
            $this->auditLogger->log('booking_draft_expired', 'booking_draft', $draft->getId());
        }
    }

    /**
     * v15 booking_draft_preview(): prices the draft again today. A promotion
     * that no longer applies is dropped and reported rather than failing.
     */
    public function preview(BookingDraft $draft): DraftPreview
    {
        $promoWarning = '';
        $promo = $draft->getPromoCode();

        try {
            $quote = $this->calculator->quote($draft->toRequest());
        } catch (\InvalidArgumentException $error) {
            if ('' === $promo || !str_contains(strtolower($error->getMessage()), 'promotion code')) {
                return DraftPreview::failed($draft, $error->getMessage(), '', $promo);
            }

            $promoWarning = 'The promotion saved with this booking is no longer available. The current price has been recalculated without it.';
            $promo = '';
            try {
                $quote = $this->calculator->quote($draft->toRequest(promo: ''));
            } catch (\InvalidArgumentException $retryError) {
                return DraftPreview::failed($draft, $retryError->getMessage(), $promoWarning, '');
            }
        }

        $available = $this->bookings->isVehicleAvailable($quote->vehicle, $quote->pickupAt, $quote->returnAt);

        return new DraftPreview(
            quote: $quote,
            available: $available,
            error: $available ? '' : 'This vehicle is no longer available for your saved dates.',
            promoWarning: $promoWarning,
            savedTotal: $draft->getEstimatedTotal(),
            effectivePromoCode: $promo,
        );
    }
}
