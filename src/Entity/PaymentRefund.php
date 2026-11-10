<?php

namespace App\Entity;

use App\Repository\PaymentRefundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money going back to a customer from one verified payment (v15 payment_refunds).
 * It may come from a cancellation, a cheaper modification, an approved early
 * return, a return settlement (security deposit) or a manual correction, and moves through the v15 statuses:
 * pending → approved → processing → refunded (or rejected / failed / cancelled).
 */
#[ORM\Entity(repositoryClass: PaymentRefundRepository::class)]
#[ORM\Table(name: 'payment_refunds')]
#[ORM\Index(name: 'idx_refunds_booking_status', columns: ['booking_id', 'status'])]
#[ORM\Index(name: 'idx_refunds_payment_status', columns: ['payment_id', 'status'])]
#[ORM\Index(name: 'idx_refunds_type_status', columns: ['refund_type', 'status'])]
#[ORM\HasLifecycleCallbacks]
class PaymentRefund
{
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_PAYMENT_CORRECTION = 'payment_correction';
    public const TYPE_SECURITY_DEPOSIT = 'security_deposit';
    public const TYPE_BOOKING_MODIFICATION = 'booking_modification';
    public const TYPE_EARLY_RETURN = 'early_return';

    /** v15 refund_type_labels() */
    public const TYPE_LABELS = [
        self::TYPE_CANCELLATION => 'Cancellation Refund',
        self::TYPE_PAYMENT_CORRECTION => 'Payment Refund',
        self::TYPE_SECURITY_DEPOSIT => 'Security Deposit Refund',
        self::TYPE_BOOKING_MODIFICATION => 'Modification Refund',
        self::TYPE_EARLY_RETURN => 'Early Return Refund',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    /** v15 refund_statuses() */
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PROCESSING, self::STATUS_REFUNDED,
        self::STATUS_REJECTED, self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    /** Still waiting to be paid out. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PROCESSING];

    /** Count against what is left to refund on the payment. */
    public const COMMITTED_STATUSES = [...self::OPEN_STATUSES, self::STATUS_REFUNDED];

    /** v15 review_refund(): allowed next statuses. */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_REJECTED],
        self::STATUS_PROCESSING => [self::STATUS_REFUNDED, self::STATUS_FAILED],
        self::STATUS_FAILED => [self::STATUS_PROCESSING, self::STATUS_REJECTED],
        self::STATUS_REFUNDED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\Column(length: 30, options: ['default' => self::TYPE_PAYMENT_CORRECTION])]
    private string $refundType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?BookingCancellationRequest $cancellationRequest;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?BookingModificationRequest $bookingModification;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'approved_by', onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(length: 120, nullable: true, options: ['default' => ''])]
    private ?string $referenceNumber = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customerNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * v15 create_refund_requests_for_booking() / create_payment_refund(). Pass
     * at most one source request; the refund type says which. Checking the
     * amount against what is left on the payment needs the other refunds, so
     * the refund service does that (see PaymentRefundRepository).
     *
     * @param key-of<self::TYPE_LABELS> $refundType
     *
     * @throws \InvalidArgumentException for an unknown type, a non-positive amount or an unverified payment
     */
    public function __construct(
        Payment $payment,
        string $refundType,
        int $amount,
        string $reason,
        ?BookingCancellationRequest $cancellationRequest = null,
        ?BookingModificationRequest $bookingModification = null,
        string $adminNote = '',
    ) {
        if (!\array_key_exists($refundType, self::TYPE_LABELS)) {
            throw new \InvalidArgumentException('Choose a valid refund type.');
        }
        if ($amount < 1) {
            throw new \InvalidArgumentException('A refund amount must be at least 1.');
        }
        if (!$payment->isPaid()) {
            throw new \InvalidArgumentException('Only a verified payment can be refunded.');
        }

        $this->payment = $payment;
        $this->booking = $payment->getBooking();
        $this->refundType = $refundType;
        $this->amount = $amount;
        $this->reason = mb_substr(trim($reason), 0, 2000);
        $this->cancellationRequest = $cancellationRequest;
        $this->bookingModification = $bookingModification;
        $this->adminNote = mb_substr(trim($adminNote), 0, 2000);
        $this->requestedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 review_refund(): moves the refund one step along. Approving (or any
     * later step) records the admin; paying out stamps processedAt and, when no
     * bank reference is given, a generated RF-YYYYMMDD-000123 one.
     *
     * @throws \DomainException when the move is not allowed from the current status
     */
    public function transitionTo(string $newStatus, User $admin, string $note = '', string $referenceNumber = ''): void
    {
        if (self::TYPE_SECURITY_DEPOSIT === $this->refundType
            && \in_array($newStatus, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            throw new \DomainException('A finalized security-deposit settlement refund cannot be rejected. Correct the settlement before finalizing it.');
        }
        if (!$this->canTransitionTo($newStatus)) {
            throw new \DomainException('That refund status transition is not allowed.');
        }

        $now = new \DateTimeImmutable();
        if (\in_array($newStatus, [self::STATUS_APPROVED, self::STATUS_PROCESSING, self::STATUS_REFUNDED], true)) {
            $this->approvedAt ??= $now;
            $this->approvedBy = $admin;
        }
        if (self::STATUS_REFUNDED === $newStatus) {
            $this->processedAt = $now;
            if ('' === trim($referenceNumber)) {
                $referenceNumber = sprintf('RF-%s-%06d', $now->format('Ymd'), (int) $this->id);
            }
        }

        $this->status = $newStatus;
        $this->referenceNumber = mb_substr(trim($referenceNumber), 0, 120);
        $this->adminNote = mb_substr(trim($note), 0, 2000);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return \in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function getNextStatuses(): array
    {
        $next = self::TRANSITIONS[$this->status] ?? [];
        if (self::TYPE_SECURITY_DEPOSIT === $this->refundType) {
            $next = array_diff($next, [self::STATUS_REJECTED, self::STATUS_CANCELLED]);
        }

        return array_values($next);
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isRefunded(): bool
    {
        return self::STATUS_REFUNDED === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getRefundType(): string
    {
        return $this->refundType;
    }

    public function getRefundTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->refundType] ?? 'Refund';
    }

    public function getCancellationRequest(): ?BookingCancellationRequest
    {
        return $this->cancellationRequest;
    }

    public function getBookingModification(): ?BookingModificationRequest
    {
        return $this->bookingModification;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getReferenceNumber(): string
    {
        return (string) $this->referenceNumber;
    }

    public function getCustomerNote(): string
    {
        return (string) $this->customerNote;
    }

    public function getAdminNote(): string
    {
        return (string) $this->adminNote;
    }

    public function getNotes(): string
    {
        return (string) $this->notes;
    }
}
