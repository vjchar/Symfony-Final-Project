<?php

namespace App\Entity;

use App\Repository\RentalAdjustmentRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An active rental's request to return early or keep the vehicle longer
 * (v15 rental_adjustment_requests). An approved extension is activated once
 * any extra cost is paid; an early return is only noted for the rental desk.
 */
#[ORM\Entity(repositoryClass: RentalAdjustmentRequestRepository::class)]
#[ORM\Table(name: 'rental_adjustment_requests')]
#[ORM\Index(name: 'idx_adjustment_booking_status', columns: ['booking_id', 'status'])]
#[ORM\Index(name: 'idx_adjustment_user_status', columns: ['user_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_adjustment_type_status', columns: ['request_type', 'status', 'requested_return_at'])]
#[ORM\HasLifecycleCallbacks]
class RentalAdjustmentRequest
{
    public const TYPE_EXTENSION = 'extension';
    public const TYPE_EARLY_RETURN = 'early_return';
    public const TYPES = [self::TYPE_EARLY_RETURN, self::TYPE_EXTENSION];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ACTIVATED = 'activated';
    public const STATUS_COMPLETED = 'completed';
    // requests that still need the rental desk
    public const UNRESOLVED_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

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

    #[ORM\Column(length: 30)]
    private string $requestType;

    #[ORM\Column]
    private \DateTimeImmutable $originalReturnAt;

    #[ORM\Column]
    private \DateTimeImmutable $requestedReturnAt;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $priceDifference;

    #[ORM\Column(length: 1500, nullable: true, options: ['default' => ''])]
    private ?string $customerReason;

    #[ORM\Column(length: 1500, nullable: true, options: ['default' => ''])]
    private ?string $adminNote = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reviewed_by', onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Booking $booking, User $user, string $requestType, \DateTimeImmutable $requestedReturnAt, int $priceDifference, string $reason)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->requestType = $requestType;
        $this->originalReturnAt = $booking->getReturnAt();
        $this->requestedReturnAt = $requestedReturnAt;
        $this->priceDifference = $priceDifference;
        $this->customerReason = mb_substr(trim($reason), 0, 1500);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 cancel_rental_adjustment_request(): the customer withdraws it.
     */
    public function withdraw(User $user): void
    {
        if (self::STATUS_PENDING !== $this->status || $this->user->getId() !== $user->getId()) {
            throw new \DomainException('That rental adjustment cannot be cancelled.');
        }
        $this->status = self::STATUS_CANCELLED;
    }

    public function approve(User $admin, string $note, ?int $priceDifference = null): void
    {
        $this->review(self::STATUS_APPROVED, $admin, $note);
        $this->priceDifference = $priceDifference ?? $this->priceDifference;
    }

    public function reject(User $admin, string $note): void
    {
        $this->review(self::STATUS_REJECTED, $admin, $note);
    }

    public function markActivated(int $priceDifference): void
    {
        $this->status = self::STATUS_ACTIVATED;
        $this->priceDifference = $priceDifference;
        $this->activatedAt = new \DateTimeImmutable();
    }

    public function isExtension(): bool
    {
        return self::TYPE_EXTENSION === $this->requestType;
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

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function getOriginalReturnAt(): \DateTimeImmutable
    {
        return $this->originalReturnAt;
    }

    public function getRequestedReturnAt(): \DateTimeImmutable
    {
        return $this->requestedReturnAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPriceDifference(): int
    {
        return $this->priceDifference;
    }

    public function getCustomerReason(): string
    {
        return (string) $this->customerReason;
    }

    public function getAdminNote(): string
    {
        return (string) $this->adminNote;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function review(string $status, User $admin, string $note): void
    {
        $this->status = $status;
        $this->adminNote = mb_substr(trim($note), 0, 1500);
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewedBy = $admin;
    }
}
