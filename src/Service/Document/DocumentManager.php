<?php

namespace App\Service\Document;

use App\Entity\CustomerDocument;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\CustomerDocumentRepository;
use App\Service\AuditLogger;
use App\Service\Booking\BookingConfirmer;
use App\Service\Booking\BookingJourneyResolver;
use App\Service\NotificationCenter;
use App\Service\PrivateFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * v15 upsert_customer_document(): a customer submits or replaces one of the
 * two required documents, and it goes back to pending verification.
 */
final class DocumentManager
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerDocumentRepository $documents,
        private readonly BookingRepository $bookings,
        private readonly BookingJourneyResolver $journeys,
        private readonly NotificationCenter $notifications,
        private readonly PrivateFileStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly BookingConfirmer $confirmer,
    ) {
    }

    /**
     * v15 review_customer_document(): an approval confirms every pending
     * booking of the customer whose deposit is already verified.
     *
     * @throws \InvalidArgumentException with the v15 message
     */
    public function review(CustomerDocument $document, string $status, string $notes, User $admin): void
    {
        $document->review($status, $notes, $admin);
        $this->entityManager->flush();

        $customer = $document->getUser();
        $bookings = $this->bookings->findForUser($customer);
        if (CustomerDocument::STATUS_APPROVED === $status) {
            foreach ($bookings as $booking) {
                $this->confirmer->confirmIfReady($booking, $admin);
            }
        }

        $context = $this->journeys->latestActionable($bookings);
        $label = str_replace('_', ' ', $document->getDocumentType());
        if (CustomerDocument::STATUS_REJECTED === $status) {
            $title = 'Document Update Required';
            $message = sprintf('Your %s could not be verified. Replace the document to continue your booking.', $label);
        } else {
            $title = 'Document Verified';
            $message = sprintf('Your %s was verified successfully.', $label);
            $message .= match (null === $context ? null : $this->journeys->resolve($context)->stage) {
                'verification' => ' Your booking requirements are still being verified.',
                'preparing' => ' Your requirements are verified and the vehicle is being prepared.',
                default => '',
            };
        }
        $this->notifications->notifyUser($customer, $title, $message, 'document', $context);
        $this->entityManager->flush();
        $this->auditLogger->log('customer_document_'.$status, 'customer_document', $document->getId(), [], $admin);
    }

    public function checklist(User $user): DocumentChecklist
    {
        return DocumentChecklist::fromDocuments($this->documents->findForUser($user));
    }

    /**
     * @throws \InvalidArgumentException with the v15 message
     */
    public function submit(User $user, string $type, string $number, string $expiryDate, ?UploadedFile $file): CustomerDocument
    {
        if (!\array_key_exists($type, CustomerDocument::TYPES)) {
            throw new \InvalidArgumentException('Choose a valid document type.');
        }
        $number = trim($number);
        if (mb_strlen($number) < 4 || mb_strlen($number) > 120) {
            throw new \InvalidArgumentException('Enter a valid document number.');
        }
        $expiry = null;
        if (CustomerDocument::TYPE_DRIVERS_LICENSE === $type) {
            $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate) ?: null;
            if (null === $expiry || $expiry->format('Y-m-d') !== $expiryDate || $expiry <= new \DateTimeImmutable('today')) {
                throw new \InvalidArgumentException('The driver’s license must have a future expiry date.');
            }
        }
        if (null === $file) {
            throw new \InvalidArgumentException('The upload could not be completed.');
        }

        $filename = $this->storage->store($file, PrivateFileStorage::DOCUMENTS, 'user-'.$user->getId().'-'.$type, PrivateFileStorage::DOCUMENT_TYPES, self::MAX_BYTES);
        $existing = $this->documents->findForUser($user);
        $document = $existing[$type] ?? new CustomerDocument($user, $type);
        $replacedFile = $document->getFilename();

        try {
            $document->replace($number, $expiry, $filename);
            $this->entityManager->persist($document);
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            $this->storage->delete(PrivateFileStorage::DOCUMENTS, $filename);
            throw $error;
        }
        // the old file is no longer reachable from the account, so it is not kept
        if ('' !== $replacedFile && $replacedFile !== $filename) {
            $this->storage->delete(PrivateFileStorage::DOCUMENTS, $replacedFile);
        }

        $allSubmitted = $this->checklist($user)->allSubmitted();
        $this->notifications->notifyUser(
            $user,
            $allSubmitted ? 'Documents Submitted — Verification Pending' : 'Document Submitted — Continue Uploads',
            $allSubmitted
                ? 'Your required documents have been submitted successfully and are awaiting verification. No action is required unless our team requests an update.'
                : sprintf('Your %s was submitted. Upload the remaining required document to continue.', CustomerDocument::TYPE_DRIVERS_LICENSE === $type ? 'driver’s license' : 'government ID'),
            'document',
            $this->journeys->latestActionable($this->bookings->findForUser($user)),
        );
        $this->entityManager->flush();
        $this->auditLogger->log('customer_document_submitted', 'customer_document', $document->getId(), ['type' => $type]);

        return $document;
    }
}
