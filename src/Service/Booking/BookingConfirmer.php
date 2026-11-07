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
 * driver's license still valid), a pending booking moves to vehicle preparation.
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

    public function isReady(Booking $booking): bool
    {
        $documents = $this->documents->findForUser($booking->getUser());
        foreach (array_keys(CustomerDocument::TYPES) as $type) {
            if (!isset($documents[$type]) || !$documents[$type]->isVerified()) {
                return false;
            }
        }

        return $this->ledger->summary($booking)->depositVerified();
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
