<?php

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\CustomerDocument;
use App\Entity\User;
use App\Repository\CustomerDocumentRepository;
use App\Service\AuditLogger;
use App\Service\NotificationCenter;
use App\Service\Payment\PaymentLedger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 booking_requirements() and confirm_booking_when_requirements_complete():
 * once the security deposit is verified and both documents are approved (the
 * driver's license still valid on the return date), a pending booking moves
 * to vehicle preparation.
 * Approving the last payment or document is what triggers it.
 */
final class BookingConfirmer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentLedger $ledger,
        private readonly CustomerDocumentRepository $documents,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public const LICENSE_TOO_SHORT = 'The driver’s license on file expires before the scheduled return. Upload a renewed license before the rental can go ahead.';

    public function isReady(Booking $booking): bool
    {
        $documents = $this->documents->findForUser($booking->getUser());
        foreach (array_keys(CustomerDocument::TYPES) as $type) {
            if (!isset($documents[$type]) || !$documents[$type]->isVerified()) {
                return false;
            }
        }

        return $this->licenseCovers($booking, $booking->getReturnAt()) && $this->ledger->summary($booking)->depositVerified();
    }

    /**
     * The customer's driver's license, if it has an expiry date, is still valid
     * on the given return (it must be valid for the whole rental).
     */
    public function licenseCovers(Booking $booking, \DateTimeImmutable $returnAt): bool
    {
        $license = $this->documents->findForUser($booking->getUser())[CustomerDocument::TYPE_DRIVERS_LICENSE] ?? null;
        $expiry = $license?->getExpiryDate();

        return null === $expiry || $expiry->setTime(23, 59, 59) >= $returnAt;
    }

    /**
     * @return bool true when the booking was confirmed now
     */
    public function confirmIfReady(Booking $booking, User $admin): bool
    {
        if ('pending' !== $booking->getStatus() || !$this->isReady($booking)) {
            return false;
        }

        $booking->confirm();
        $this->notifications->notifyUser(
            $booking->getUser(),
            'Requirements Verified — Vehicle Preparation',
            sprintf('Your payment and required documents for booking %s have been verified. Our team is preparing your vehicle. No action is required right now.', $booking->getReference()),
            'booking',
            $booking,
        );
        $this->entityManager->flush();
        $this->auditLogger->log('booking_status_updated', 'booking', $booking->getId(), ['from' => 'pending', 'to' => 'confirmed', 'admin_id' => $admin->getId()], $admin);

        return true;
    }
}
