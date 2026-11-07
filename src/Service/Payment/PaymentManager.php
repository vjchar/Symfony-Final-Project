<?php

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use App\Entity\Payment;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\PaymentRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Service\AuditLogger;
use App\Service\Booking\BookingConfirmer;
use App\Service\Booking\BookingJourneyResolver;
use App\Service\Booking\BookingModificationManager;
use App\Service\Booking\RentalAdjustmentManager;
use App\Service\Booking\RunsInTransaction;
use App\Service\NotificationCenter;
use App\Service\PrivateFileStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * v15 create_payment_request(): a customer reports a payment and it waits
 * for an administrator to verify it. The amount is checked against what is
 * due; an approved extension or modification must be paid exactly.
 */
final class PaymentManager
{
    use RunsInTransaction;

    public const MAX_PROOF_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentLedger $ledger,
        private readonly PaymentRepository $payments,
        private readonly RentalAdjustmentRequestRepository $adjustments,
        private readonly BookingModificationRequestRepository $modifications,
        private readonly BookingJourneyResolver $journeys,
        private readonly NotificationCenter $notifications,
        private readonly PrivateFileStorage $storage,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%env(PAYMENT_GCASH_NAME)%')] private readonly string $gcashName,
        #[Autowire('%env(PAYMENT_GCASH_NUMBER)%')] private readonly string $gcashNumber,
        #[Autowire('%env(PAYMENT_BANK_NAME)%')] private readonly string $bankName,
        #[Autowire('%env(PAYMENT_BANK_ACCOUNT_NAME)%')] private readonly string $bankAccountName,
        #[Autowire('%env(PAYMENT_BANK_ACCOUNT_NUMBER)%')] private readonly string $bankAccountNumber,
        private readonly BookingConfirmer $confirmer,
        private readonly RentalAdjustmentManager $rentalAdjustments,
        private readonly BookingModificationManager $bookingModifications,
    ) {
    }

    /**
     * v15 payment_account_details(): where customers send GCash and bank payments.
     *
     * @return array{gcash: array{account_name: string, account_number: string}, bank_transfer: array{bank_name: string, account_name: string, account_number: string}}
     */
    public function accounts(): array
    {
        return [
            'gcash' => ['account_name' => $this->gcashName, 'account_number' => $this->gcashNumber],
            'bank_transfer' => ['bank_name' => $this->bankName, 'account_name' => $this->bankAccountName, 'account_number' => $this->bankAccountNumber],
        ];
    }

    /**
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function submit(Booking $booking, User $user, string $type, string $method, string $amount, string $reference, ?UploadedFile $proof): Payment
    {
        if (!$booking->isOwnedBy($user)) {
            throw new \DomainException('This booking does not belong to your account.');
        }
        if (\in_array($booking->getStatus(), ['cancelled', 'rejected', 'no_show'], true)) {
            throw new \DomainException('This booking no longer accepts payments.');
        }
        if (!\in_array($type, Payment::TYPES, true) || !\in_array($method, Payment::METHODS, true)) {
            throw new \InvalidArgumentException('Choose a valid payment type and method.');
        }
        $amountValue = filter_var($amount, \FILTER_VALIDATE_INT);
        if (false === $amountValue) {
            throw new \InvalidArgumentException('Enter a valid whole-peso amount.');
        }

        $summary = $this->ledger->summary($booking);
        $adjustment = null;
        $modification = null;

        if (Payment::TYPE_EXTENSION === $type) {
            $adjustment = $this->adjustments->findApprovedExtensionFor($booking);
            if (null === $adjustment || $adjustment->getUser()->getId() !== $user->getId()) {
                throw new \DomainException('That extension is not awaiting payment.');
            }
            $outstanding = max(0, $adjustment->getPriceDifference() - $this->payments->sumSubmittedForAdjustment($adjustment));
            if ($outstanding < 1 || $amountValue !== $outstanding) {
                throw new \InvalidArgumentException('Submit the exact outstanding extension amount.');
            }
        } elseif (Payment::TYPE_MODIFICATION === $type) {
            $modification = $this->modifications->findAwaitingPaymentFor($booking);
            if (null === $modification || $modification->getUser()->getId() !== $user->getId()) {
                throw new \DomainException('That booking modification is not awaiting payment.');
            }
            $outstanding = max(0, $modification->getPriceDifference() - $this->payments->sumSubmittedForModification($modification));
            if ($outstanding < 1 || $amountValue !== $outstanding) {
                throw new \InvalidArgumentException('Submit the exact outstanding booking modification amount.');
            }
        } elseif (Payment::TYPE_EXTRA_CHARGE === $type) {
            if (!\in_array($booking->getStatus(), ['returned', 'completed'], true)) {
                throw new \DomainException('Return-charge payments are available only after vehicle check-in and settlement.');
            }
            $outstanding = max(0, $summary->extraDue - $this->payments->sumPendingOfType($booking, Payment::TYPE_EXTRA_CHARGE));
            if ($amountValue < 1 || $amountValue > $outstanding) {
                throw new \InvalidArgumentException(sprintf('Enter an amount no greater than the outstanding return balance of ₱%s.', number_format($outstanding)));
            }
        } else {
            $maximum = Payment::TYPE_DEPOSIT === $type ? $summary->depositDue : $summary->rentalDue;
            if ($amountValue < 1 || $amountValue > $maximum) {
                throw new \InvalidArgumentException('Enter an amount no greater than the current amount due.');
            }
        }

        $electronic = Payment::METHOD_CASH !== $method;
        if ($electronic && mb_strlen(trim($reference)) < 4) {
            throw new \InvalidArgumentException('Enter the transaction/reference number from your payment.');
        }
        if ($electronic && null === $proof) {
            throw new \InvalidArgumentException('Upload your payment proof before submitting the payment.');
        }

        $proofFilename = null === $proof
            ? ''
            : $this->storage->store($proof, PrivateFileStorage::PAYMENT_PROOFS, 'payment-'.$booking->getReference(), PrivateFileStorage::DOCUMENT_TYPES, self::MAX_PROOF_BYTES);

        try {
            $payment = new Payment($booking, $user, $type, $method, $amountValue, $reference, $proofFilename, $adjustment, $modification);
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            $this->storage->delete(PrivateFileStorage::PAYMENT_PROOFS, $proofFilename);
            throw $error;
        }

        [$title, $message] = $this->customerMessage($booking, $type);
        $this->notifications->notifyUser($user, $title, $message, 'payment', $booking);
        $this->notifications->notifyAdmins(
            'Payment awaiting verification',
            sprintf('A %s payment was submitted for booking %s.', str_replace('_', ' ', $type), $booking->getReference()),
            'payment',
            $booking,
        );
        $this->entityManager->flush();
        $this->auditLogger->log('payment_submitted', 'payment', $payment->getId(), [
            'booking_id' => $booking->getId(),
            'rental_adjustment_id' => $adjustment?->getId(),
            'booking_modification_id' => $modification?->getId(),
        ]);

        return $payment;
    }

    /**
     * v15 review_payment(): an administrator marks a payment paid or failed.
     * A verified extension or modification payment activates the change, and a
     * verified deposit confirms the booking once the documents are approved too.
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function review(Payment $payment, string $status, string $notes, User $admin): void
    {
        $this->transactional(function () use ($payment, $status, $notes, $admin): void {
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $adjustment = $payment->getRentalAdjustment();
            $modification = $payment->getBookingModification();
            $paid = Payment::STATUS_PAID === $status;

            if ($paid && Payment::TYPE_EXTENSION === $payment->getPaymentType()) {
                if (null === $adjustment) {
                    throw new \DomainException('This extension payment is missing its rental adjustment link.');
                }
                if (RentalAdjustmentRequest::STATUS_APPROVED !== $adjustment->getStatus()) {
                    throw new \DomainException('This extension is no longer awaiting activation.');
                }
            }
            if ($paid && Payment::TYPE_MODIFICATION === $payment->getPaymentType()) {
                if (null === $modification) {
                    throw new \DomainException('This modification payment is missing its booking modification link.');
                }
                if (BookingModificationRequest::STATUS_APPROVED !== $modification->getStatus()) {
                    throw new \DomainException('This booking modification is no longer awaiting activation.');
                }
            }

            $payment->review($status, $notes, $admin);
            $this->entityManager->flush();

            if ($paid && null !== $adjustment && Payment::TYPE_EXTENSION === $payment->getPaymentType()) {
                $this->rentalAdjustments->activatePaidExtension($adjustment, $this->payments->sumPaidForAdjustment($adjustment), $admin);
            }
            if ($paid && null !== $modification && Payment::TYPE_MODIFICATION === $payment->getPaymentType()) {
                $this->bookingModifications->activatePaid($modification, $admin);
            }

            $booking = $payment->getBooking();
            if ($paid) {
                $this->confirmer->confirmIfReady($booking, $admin);
            }

            $reference = $booking->getReference();
            if ($paid) {
                $next = $this->journeys->resolve($booking);
                $message = sprintf('Your %s payment for booking %s was verified.', str_replace('_', ' ', $payment->getPaymentType()), $reference)
                    .match ($next->stage) {
                        'documents' => ' Next step: upload your required documents.',
                        'verification' => ' Your submitted requirements are now in verification.',
                        default => '',
                    };
                $this->notifications->notifyUser($payment->getUser(), 'Payment Verified', $message, 'payment', $booking);
            } else {
                $this->notifications->notifyUser(
                    $payment->getUser(),
                    'Payment Update Required',
                    sprintf('Your payment for booking %s could not be verified. Review the payment and submit an updated payment if required.', $reference),
                    'payment',
                    $booking,
                );
            }
            $this->entityManager->flush();
        });

        $this->auditLogger->log('payment_'.$status, 'payment', $payment->getId(), [], $admin);
    }

    /**
     * @return array{string, string} the v15 notification title and message for the customer
     */
    private function customerMessage(Booking $booking, string $type): array
    {
        $reference = $booking->getReference();
        if (Payment::TYPE_EXTENSION === $type) {
            return ['Extension Payment Submitted', sprintf('Your extension payment for booking %s is awaiting verification.', $reference)];
        }
        if (Payment::TYPE_MODIFICATION === $type) {
            return ['Modification Payment Submitted', sprintf('Your additional payment for the approved booking changes on %s is awaiting verification.', $reference)];
        }

        $next = $this->journeys->resolve($booking);

        return match ($next->stage) {
            'documents' => ['Payment Submitted — Upload Documents', sprintf('Your payment for booking %s was submitted and is awaiting verification. Next step: upload your required documents.', $reference)],
            'verification' => ['Payment Submitted — Verification Pending', sprintf('Your payment for booking %s was submitted. Your current requirements are now awaiting verification.', $reference)],
            default => ['Payment Submitted', sprintf('Your payment for booking %s was submitted. %s', $reference, $next->message)],
        };
    }
}
