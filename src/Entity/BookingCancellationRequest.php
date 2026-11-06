<?php

namespace App\Entity;

use App\Repository\BookingCancellationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's request to cancel before pickup (v15 booking_cancellation_requests).
 * The refund split is estimated when it is sent and recalculated at review.
 */
#[ORM\Entity(repositoryClass: BookingCancellationRequestRepository::class)]
#[ORM\Table(name: 'booking_cancellation_requests')]
#[ORM\Index(name: 'idx_booking_cancellation_booking_status', columns: ['booking_id', 'status'])]
#[ORM\Index(name: 'idx_booking_cancellation_user', columns: ['user_id', 'requested_at'])]
#[ORM\HasLifecycleCallbacks]
class BookingCancellationRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $refundableAmount;

    #[ORM\Column(options: ['default' => 0])]
    private int $nonRefundableAmount;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reviewed_by', onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Booking $booking, User $user, string $reason, int $refundableAmount, int $nonRefundableAmount)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->reason = mb_substr(trim($reason), 0, 2000);
        $this->refundableAmount = $refundableAmount;
        $this->nonRefundableAmount = $nonRefundableAmount;
        $this->requestedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param self::STATUS_APPROVED|self::STATUS_REJECTED $decision
     */
    public function review(string $decision, User $admin, string $note, int $refundableAmount, int $nonRefundableAmount): void
    {
        $this->status = $decision;
        $this->refundableAmount = $refundableAmount;
        $this->nonRefundableAmount = $nonRefundableAmount;
        $this->adminNote = mb_substr(trim($note), 0, 2000);
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewedBy = $admin;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRefundableAmount(): int
    {
        return $this->refundableAmount;
    }

    public function getNonRefundableAmount(): int
    {
        return $this->nonRefundableAmount;
    }

    public function getAdminNote(): string
    {
        return (string) $this->adminNote;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }
}
