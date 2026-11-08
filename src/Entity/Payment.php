<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer payment submitted for verification (v15 payments). GCash and
 * bank transfers carry a transaction reference and a proof file; cash is paid
 * at the branch. Only payments an administrator marks paid count toward a
 * booking (see PaymentLedger).
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\Index(name: 'idx_payments_booking_status', columns: ['booking_id', 'status'])]
#[ORM\Index(name: 'idx_payments_adjustment', columns: ['rental_adjustment_id', 'status'])]
#[ORM\Index(name: 'idx_payments_modification', columns: ['booking_modification_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_BALANCE = 'balance';
    public const TYPE_EXTRA_CHARGE = 'extra_charge';
    public const TYPE_EXTENSION = 'extension';
    public const TYPE_MODIFICATION = 'modification';
    public const TYPES = [self::TYPE_DEPOSIT, self::TYPE_BALANCE, self::TYPE_EXTENSION, self::TYPE_MODIFICATION, self::TYPE_EXTRA_CHARGE];

    public const METHOD_GCASH = 'gcash';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CASH = 'cash';
    public const METHODS = [self::METHOD_GCASH, self::METHOD_BANK_TRANSFER, self::METHOD_CASH];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'rental_adjustment_id', onDelete: 'SET NULL')]
    private ?RentalAdjustmentRequest $rentalAdjustment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'booking_modification_id', onDelete: 'SET NULL')]
    private ?BookingModificationRequest $bookingModification;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 30)]
    private string $paymentType;

    #[ORM\Column(length: 40)]
    private string $method;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 120, nullable: true, options: ['default' => ''])]
    private ?string $transactionReference;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    private ?string $proofFilename;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by', onDelete: 'SET NULL')]
    private ?User $recordedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Booking $booking,
        User $user,
        string $paymentType,
        string $method,
        int $amount,
        string $transactionReference,
        string $proofFilename,
        ?RentalAdjustmentRequest $rentalAdjustment = null,
        ?BookingModificationRequest $bookingModification = null,
    ) {
        $this->booking = $booking;
        $this->user = $user;
        $this->paymentType = $paymentType;
        $this->method = $method;
        $this->amount = $amount;
        $this->transactionReference = mb_substr(trim($transactionReference), 0, 120);
        $this->proofFilename = $proofFilename;
        $this->rentalAdjustment = $rentalAdjustment;
        $this->bookingModification = $bookingModification;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 review_payment(): an administrator verifies or fails the payment.
     */
    public function review(string $status, string $notes, User $admin): void
    {
        if (!\in_array($status, [self::STATUS_PAID, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException('Choose a valid payment status.');
        }
        // a decision is final: flipping a verified payment would leave the
        // booking confirmation, applied changes and refunds out of step with it
        if (!$this->isPending()) {
            throw new \DomainException('This payment was already reviewed.');
        }
        $this->status = $status;
        $this->notes = mb_substr(trim($notes), 0, 2000);
        $this->recordedBy = $admin;
        $this->paidAt = self::STATUS_PAID === $status ? new \DateTimeImmutable() : null;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isPaid(): bool
    {
        return self::STATUS_PAID === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getRentalAdjustment(): ?RentalAdjustmentRequest
    {
        return $this->rentalAdjustment;
    }

    public function getBookingModification(): ?BookingModificationRequest
    {
        return $this->bookingModification;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPaymentType(): string
    {
        return $this->paymentType;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * v15 payment_method_label().
     */
    public function getMethodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_GCASH => 'GCash',
            self::METHOD_BANK_TRANSFER => 'Bank Transfer',
            self::METHOD_CASH => 'Cash at Branch',
            default => ucwords(str_replace('_', ' ', $this->method)),
        };
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getTransactionReference(): string
    {
        return (string) $this->transactionReference;
    }

    public function getProofFilename(): string
    {
        return (string) $this->proofFilename;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getNotes(): string
    {
        return (string) $this->notes;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
