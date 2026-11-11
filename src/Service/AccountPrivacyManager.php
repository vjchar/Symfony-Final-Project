<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\BookingDraft;
use App\Entity\CustomerDocument;
use App\Entity\Favorite;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\CustomerDocumentRepository;
use App\Repository\PaymentRefundRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A customer's own data: a copy of everything kept about them, closing their
 * account, and the retention rule for ID scans.
 * - Closing the account deletes the ID documents, favorites, saved bookings
 *   and notifications. If bookings or payments exist they are kept for the
 *   business records, but the account is anonymised and can no longer sign
 *   in; otherwise the account is removed entirely.
 * - ID documents are deleted once DOCUMENT_RETENTION has passed since the
 *   customer's last rental ended (or since upload, without rentals).
 */
final class AccountPrivacyManager
{
    public const DOCUMENT_RETENTION = '1 year';

    // a booking still in progress keeps the account (and its documents) in use
    private const OPEN_BOOKING_STATUSES = ['pending', 'confirmed', 'ready', 'active', 'returned'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly CustomerDocumentRepository $documents,
        private readonly PaymentRefundRepository $refunds,
        private readonly PrivateFileStorage $storage,
        private readonly ReferenceGuard $references,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<string, mixed> everything kept about the customer, for a JSON download
     */
    public function export(User $user): array
    {
        $date = static fn (?\DateTimeInterface $at): ?string => $at?->format(\DATE_ATOM);
        $bookings = $this->bookings->findForUser($user);

        return [
            'exported_at' => $date(new \DateTimeImmutable()),
            'account' => [
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'role' => $user->getRole(),
                'status' => $user->getStatus(),
                'created_at' => $date($user->getCreatedAt()),
                'last_login_at' => $date($user->getLastLoginAt()),
            ],
            'documents' => array_values(array_map(static fn (CustomerDocument $document): array => [
                'type' => $document->getLabel(),
                'number' => $document->getDocumentNumber(),
                'expiry_date' => $document->getExpiryDate()?->format('Y-m-d'),
                'status' => $document->getStatus(),
                'uploaded_at' => $date($document->getCreatedAt()),
            ], $this->documents->findForUser($user))),
            'bookings' => array_map(fn (Booking $booking): array => [
                'reference' => $booking->getReference(),
                'vehicle' => $booking->getVehicle()->getName(),
                'status' => $booking->getStatus(),
                'pickup_at' => $date($booking->getPickupAt()),
                'return_at' => $date($booking->getReturnAt()),
                'pickup_method' => $booking->getPickupMethod(),
                'pickup_location' => $booking->getPickupLocation(),
                'delivery_address' => $booking->getDeliveryAddress(),
                'total' => $booking->getTotal(),
                'deposit' => $booking->getDeposit(),
                'created_at' => $date($booking->getCreatedAt()),
                'payments' => array_map(static fn (Payment $payment): array => [
                    'type' => $payment->getPaymentType(),
                    'method' => $payment->getMethod(),
                    'amount' => $payment->getAmount(),
                    'status' => $payment->getStatus(),
                    'reference' => $payment->getTransactionReference(),
                    'paid_at' => $date($payment->getPaidAt()),
                ], $this->entityManager->getRepository(Payment::class)->findBy(['booking' => $booking], ['id' => 'ASC'])),
            ], $bookings),
            'refunds' => array_map(static fn (PaymentRefund $refund): array => [
                'booking' => $refund->getBooking()->getReference(),
                'type' => $refund->getRefundTypeLabel(),
                'amount' => $refund->getAmount(),
                'status' => $refund->getStatus(),
                'processed_at' => $date($refund->getProcessedAt()),
            ], $this->refunds->findForUser($user)),
            'reviews' => array_map(static fn (Review $review): array => [
                'booking' => $review->getBooking()->getReference(),
                'title' => $review->getTitle(),
                'body' => $review->getBody(),
                'status' => $review->getStatus(),
            ], $this->entityManager->getRepository(Review::class)->findBy(['user' => $user], ['id' => 'ASC'])),
        ];
    }

    /**
     * @throws \DomainException while a booking or refund is still in progress, or for staff accounts
     */
    public function assertClosable(User $user): void
    {
        if (!$user->isCustomer()) {
            throw new \DomainException('Staff and administrator accounts are closed by an administrator.');
        }
        foreach ($this->bookings->findForUser($user) as $booking) {
            if (\in_array($booking->getStatus(), self::OPEN_BOOKING_STATUSES, true)) {
                throw new \DomainException(sprintf('Booking %s is still in progress. You can close your account once it is completed or cancelled.', $booking->getReference()));
            }
        }
        foreach ($this->refunds->findForUser($user) as $refund) {
            if ($refund->isOpen()) {
                throw new \DomainException('A refund is still on its way to you. You can close your account once it has been processed.');
            }
        }
    }

    /**
     * @throws \DomainException see assertClosable()
     */
    public function close(User $user): void
    {
        $this->assertClosable($user);
        $id = $user->getId();

        foreach ($this->documents->findForUser($user) as $document) {
            $this->deleteDocument($document);
        }
        foreach ([Favorite::class, BookingDraft::class, Notification::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.user = :user', $class))->execute(['user' => $user]);
        }
        $this->entityManager->flush();

        if ($this->references->isReferenced($user)) {
            // bookings, payments and reviews stay for the records, without who they were
            $user->setName('Deleted customer')
                ->setEmail(sprintf('deleted-%d@deleted.invalid', $id))
                ->setPhone('')
                ->setPassword('!'.bin2hex(random_bytes(16)))
                ->setStatus(User::STATUS_INACTIVE);
            $user->clearPasswordReset();
        } else {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();

        $this->auditLogger->log('account_closed', 'user', $id);
    }

    /**
     * Deletes the ID scans of customers whose last rental ended more than
     * DOCUMENT_RETENTION ago (or who never rented and uploaded them that long ago).
     *
     * @return int documents deleted
     */
    public function purgeExpiredDocuments(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $cutoff = $now->modify('-'.self::DOCUMENT_RETENTION);
        $purged = 0;
        $byUser = [];
        foreach ($this->entityManager->getRepository(CustomerDocument::class)->createQueryBuilder('d')
            ->where('d.updatedAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult() as $document) {
            $byUser[$document->getUser()->getId()][] = $document;
        }

        foreach ($byUser as $documents) {
            $user = $documents[0]->getUser();
            $lastUse = null;
            foreach ($this->bookings->findForUser($user) as $booking) {
                if (\in_array($booking->getStatus(), self::OPEN_BOOKING_STATUSES, true)) {
                    continue 2;
                }
                $ended = $booking->getReturnedAt() ?? $booking->getReturnAt();
                $lastUse = max($lastUse ?? $ended, $ended);
            }
            if (null !== $lastUse && $lastUse > $cutoff) {
                continue;
            }

            foreach ($documents as $document) {
                $this->deleteDocument($document);
                ++$purged;
            }
            $this->notifications->notifyUser($user, 'ID Documents Removed', sprintf(
                'For your privacy, the ID documents on your account were deleted because they were not used for a rental in the last %s. Upload them again before your next booking.',
                self::DOCUMENT_RETENTION,
            ), 'document');
            $this->entityManager->flush();
            $this->auditLogger->log('documents_purged', 'user', $user->getId(), ['count' => \count($documents)]);
        }

        return $purged;
    }

    private function deleteDocument(CustomerDocument $document): void
    {
        $this->storage->delete(PrivateFileStorage::DOCUMENTS, $document->getFilename());
        $this->entityManager->remove($document);
    }
}
