<?php

namespace App\Entity;

use App\Form\Model\BookingRequest;
use App\Repository\BookingDraftRepository;
use App\Service\Booking\BookingQuote;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A "Save for Later" booking plan in the v15 booking_drafts table. It never
 * holds the vehicle and its totals are only estimates; everything is priced
 * again when the customer continues.
 */
#[ORM\Entity(repositoryClass: BookingDraftRepository::class)]
#[ORM\Table(name: 'booking_drafts')]
#[ORM\Index(name: 'idx_booking_drafts_user_status', columns: ['user_id', 'status', 'updated_at'])]
#[ORM\Index(name: 'idx_booking_drafts_expires', columns: ['status', 'expires_at'])]
#[ORM\HasLifecycleCallbacks]
class BookingDraft
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_CONVERTED = 'converted';

    // v15 BOOKING_DRAFT_TTL_DAYS
    public const LIFETIME = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column]
    private \DateTimeImmutable $pickupAt;

    #[ORM\Column]
    private \DateTimeImmutable $returnAt;

    #[ORM\Column(length: 40)]
    private string $pickupMethod;

    #[ORM\Column(length: 140)]
    private string $pickupLocation;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    private ?string $deliveryAddress = '';

    #[ORM\Column(length: 40, nullable: true, options: ['default' => ''])]
    private ?string $promoCode = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedSubtotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedAddonsTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedDeliveryFee = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedDiscount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $estimatedDeposit = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $specialRequests = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSavedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Booking $convertedBooking = null;

    /** @var Collection<int, BookingDraftAddon> */
    #[ORM\OneToMany(targetEntity: BookingDraftAddon::class, mappedBy: 'draft', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $addons;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $reference, User $user, BookingQuote $quote)
    {
        $this->reference = $reference;
        $this->user = $user;
        $this->addons = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->applyQuote($quote);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Stores the plan and its current estimate, and starts a fresh 7-day lifetime.
     * Call removeAddons() and flush first when the draft is already saved.
     */
    public function applyQuote(BookingQuote $quote): void
    {
        $this->vehicle = $quote->vehicle;
        $this->pickupAt = $quote->pickupAt;
        $this->returnAt = $quote->returnAt;
        $this->pickupMethod = $quote->pickupMethod;
        $this->pickupLocation = $quote->pickupLocation;
        $this->deliveryAddress = $quote->deliveryAddress;
        $this->promoCode = $quote->promoCode();
        $this->estimatedSubtotal = $quote->subtotal;
        $this->estimatedAddonsTotal = $quote->addonsTotal;
        $this->estimatedDeliveryFee = $quote->deliveryFee;
        $this->estimatedDiscount = $quote->discount;
        $this->estimatedTotal = $quote->total();
        $this->estimatedDeposit = $quote->deposit();
        $this->specialRequests = $quote->specialRequests;
        foreach ($quote->addons as $line) {
            $this->addons->add(new BookingDraftAddon($this, $line->addon, $line->quantity));
        }

        $now = new \DateTimeImmutable();
        $this->lastSavedAt = $now;
        $this->expiresAt = $now->modify(self::LIFETIME);
    }

    public function removeAddons(): void
    {
        $this->addons->clear();
    }

    /**
     * v15 booking_draft_raw_input(): the saved plan as booking form input.
     */
    public function toRequest(?string $promo = null): BookingRequest
    {
        $request = new BookingRequest();
        $request->vehicle = (string) $this->vehicle->getSlug();
        $request->pickupDate = $this->pickupAt->format('Y-m-d');
        $request->pickupTime = $this->pickupAt->format('H:i');
        $request->returnDate = $this->returnAt->format('Y-m-d');
        $request->returnTime = $this->returnAt->format('H:i');
        $request->pickupMethod = $this->pickupMethod;
        $request->location = $this->pickupLocation;
        $request->deliveryAddress = (string) $this->deliveryAddress;
        $request->promo = $promo ?? (string) $this->promoCode;
        $request->specialRequests = (string) $this->specialRequests;
        foreach ($this->addons as $line) {
            $key = (string) $line->getAddon()->getAddonKey();
            $request->addons[] = $key;
            if (BookingRequest::CHILD_SEAT === $key) {
                $request->childSeats = (string) max(1, min(4, $line->getQuantity()));
            }
        }

        return $request;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getPickupAt(): \DateTimeImmutable
    {
        return $this->pickupAt;
    }

    public function getReturnAt(): \DateTimeImmutable
    {
        return $this->returnAt;
    }

    public function getPickupMethod(): string
    {
        return $this->pickupMethod;
    }

    public function getPromoCode(): string
    {
        return (string) $this->promoCode;
    }

    public function getEstimatedTotal(): int
    {
        return $this->estimatedTotal;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }

    public function expire(): void
    {
        $this->status = self::STATUS_EXPIRED;
    }

    public function discard(): void
    {
        $this->status = self::STATUS_DISCARDED;
    }

    public function markConverted(Booking $booking): void
    {
        $this->status = self::STATUS_CONVERTED;
        $this->convertedBooking = $booking;
    }

    public function getConvertedBooking(): ?Booking
    {
        return $this->convertedBooking;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastSavedAt(): \DateTimeImmutable
    {
        return $this->lastSavedAt;
    }

    /**
     * @return Collection<int, BookingDraftAddon>
     */
    public function getAddons(): Collection
    {
        return $this->addons;
    }
}
