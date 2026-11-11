<?php

namespace App\Entity;

use App\Repository\BookingModificationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's pre-pickup change to a booking (v15 booking_modification_requests).
 * The booking keeps its current details until the request is activated:
 * straight away on approval when it costs nothing more, otherwise once the
 * extra payment is verified.
 */
#[ORM\Entity(repositoryClass: BookingModificationRequestRepository::class)]
#[ORM\Table(name: 'booking_modification_requests')]
#[ORM\Index(name: 'idx_booking_modification_booking_status', columns: ['booking_id', 'status'])]
#[ORM\Index(name: 'idx_booking_modification_user', columns: ['user_id', 'requested_at'])]
#[ORM\HasLifecycleCallbacks]
class BookingModificationRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ACTIVATED = 'activated';
    // requests that still block another modification or a cancellation
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

    #[ORM\Column(length: 30, options: ['default' => 'pre_pickup'])]
    private string $requestType = 'pre_pickup';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $originalData;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $requestedData;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vehicle $requestedVehicle;

    #[ORM\Column(options: ['default' => 0])]
    private int $priceDifference;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customerReason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

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

    /**
     * @param array<string, mixed> $originalData
     * @param array<string, mixed> $requestedData
     */
    public function __construct(Booking $booking, User $user, array $originalData, array $requestedData, Vehicle $requestedVehicle, int $priceDifference, string $reason)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->originalData = $originalData;
        $this->requestedData = $requestedData;
        $this->requestedVehicle = $requestedVehicle;
        $this->priceDifference = $priceDifference;
        $this->customerReason = mb_substr(trim($reason), 0, 2000);
        $this->requestedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 cancel_pending_booking_modification(): the customer withdraws it.
     */
    public function withdraw(User $user): void
    {
        if (self::STATUS_PENDING !== $this->status || $this->user->getId() !== $user->getId()) {
            throw new \DomainException('That modification request can no longer be cancelled.');
        }
        $this->status = self::STATUS_CANCELLED;
    }

    /**
     * @param array<string, mixed> $requestedData the request re-priced at review time
     */
    public function approve(User $admin, string $note, int $priceDifference, array $requestedData, Vehicle $requestedVehicle): void
    {
        $this->review(self::STATUS_APPROVED, $admin, $note, $priceDifference);
        $this->requestedData = $requestedData;
        $this->requestedVehicle = $requestedVehicle;
    }

    public function reject(User $admin, string $note, int $priceDifference): void
    {
        $this->review(self::STATUS_REJECTED, $admin, $note, $priceDifference);
    }

    /**
     * The booking itself was closed (expired, rejected, cancelled), so an
     * open request no longer applies.
     */
    public function closeWithBooking(): void
    {
        if (\in_array($this->status, self::UNRESOLVED_STATUSES, true)) {
            $this->status = self::STATUS_CANCELLED;
        }
    }

    public function markActivated(): void
    {
        $this->status = self::STATUS_ACTIVATED;
        $this->activatedAt = new \DateTimeImmutable();
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

    /**
     * @return array<string, mixed>
     */
    public function getOriginalData(): array
    {
        return $this->originalData;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestedData(): array
    {
        return $this->requestedData;
    }

    public function getRequestedVehicle(): ?Vehicle
    {
        return $this->requestedVehicle;
    }

    public function getPriceDifference(): int
    {
        return $this->priceDifference;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCustomerReason(): string
    {
        return (string) $this->customerReason;
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

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    private function review(string $status, User $admin, string $note, int $priceDifference): void
    {
        $this->status = $status;
        $this->priceDifference = $priceDifference;
        $this->adminNote = mb_substr(trim($note), 0, 2000);
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewedBy = $admin;
    }
}
