<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use App\Service\Booking\BookingQuote;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reservation in the v15 bookings table, priced from a BookingQuote. An
 * approved modification re-prices it and an extension moves its return.
 * (v15's admin workflow columns such as approved_at, started_at and
 * admin_notes arrive with the admin bookings pages.)
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(name: 'idx_bookings_vehicle_dates', columns: ['vehicle_id', 'pickup_at', 'return_at', 'status'])]
#[ORM\Index(name: 'idx_bookings_user', columns: ['user_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Booking
{
    // v15 booking_statuses()
    public const STATUSES = ['pending', 'confirmed', 'ready', 'active', 'returned', 'completed', 'cancelled', 'rejected', 'no_show'];
    // statuses that hold the vehicle for their dates (v15 vehicle_available())
    public const BLOCKING_STATUSES = ['pending', 'confirmed', 'ready', 'active'];
    // statuses that can still be modified or cancelled before pickup
    public const PRE_PICKUP_STATUSES = ['pending', 'confirmed', 'ready'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column]
    private \DateTimeImmutable $pickupAt;

    #[ORM\Column]
    private \DateTimeImmutable $returnAt;

    // the return booked at first, kept once a rental adjustment is requested
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $originalReturnAt = null;

    #[ORM\Column(length: 40)]
    private string $pickupMethod;

    #[ORM\Column(length: 140)]
    private string $pickupLocation;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    private ?string $deliveryAddress = '';

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(length: 40, nullable: true, options: ['default' => ''])]
    private ?string $promoCode = '';

    #[ORM\Column]
    private int $subtotal;

    #[ORM\Column(options: ['default' => 0])]
    private int $addonsTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $deliveryFee = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $discount = 0;

    #[ORM\Column]
    private int $total;

    #[ORM\Column]
    private int $deposit;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $specialRequests = null;

    /** @var Collection<int, BookingAddon> */
    #[ORM\OneToMany(targetEntity: BookingAddon::class, mappedBy: 'booking', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $addons;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $reference, User $user, BookingQuote $quote)
    {
        $this->reference = $reference;
        $this->user = $user;
        $this->deposit = $quote->deposit();
        $this->addons = new ArrayCollection();
        $this->applyQuote($quote);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 apply_booking_modification(): the approved request replaces the
     * vehicle, schedule, add-ons and prices. As in v15 the deposit stays, and
     * a booking that was ready goes back to confirmed.
     */
    public function reschedule(BookingQuote $quote): void
    {
        if (!$this->isPrePickup()) {
            throw new \DomainException('The booking is no longer eligible for pre-pickup modification.');
        }
        $this->addons->clear();
        $this->applyQuote($quote);
        if ('ready' === $this->status) {
            $this->status = 'confirmed';
        }
    }

    /**
     * v15 confirm_booking_when_requirements_complete(): a pending booking whose
     * deposit and documents are verified moves to vehicle preparation.
     */
    public function confirm(): void
    {
        if ('pending' !== $this->status) {
            throw new \DomainException('That booking status transition is not allowed.');
        }
        $this->status = 'confirmed';
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->cancelledAt = new \DateTimeImmutable();
    }

    /**
     * Remembers the first scheduled return before an adjustment changes it.
     */
    public function keepOriginalReturn(): void
    {
        $this->originalReturnAt ??= $this->returnAt;
    }

    /**
     * v15 activate_extension_request(): the later return, re-priced.
     */
    public function extendTo(\DateTimeImmutable $returnAt, int $subtotal, int $addonsTotal, int $discount, int $total): void
    {
        $this->keepOriginalReturn();
        $this->returnAt = $returnAt;
        $this->subtotal = $subtotal;
        $this->addonsTotal = $addonsTotal;
        $this->discount = $discount;
        $this->total = $total;
    }

    public function isPrePickup(): bool
    {
        return \in_array($this->status, self::PRE_PICKUP_STATUSES, true);
    }

    private function applyQuote(BookingQuote $quote): void
    {
        $this->vehicle = $quote->vehicle;
        $this->pickupAt = $quote->pickupAt;
        $this->returnAt = $quote->returnAt;
        $this->pickupMethod = $quote->pickupMethod;
        $this->pickupLocation = $quote->pickupLocation;
        $this->deliveryAddress = $quote->deliveryAddress;
        $this->promoCode = $quote->promoCode();
        $this->subtotal = $quote->subtotal;
        $this->addonsTotal = $quote->addonsTotal;
        $this->deliveryFee = $quote->deliveryFee;
        $this->discount = $quote->discount;
        $this->total = $quote->total();
        $this->specialRequests = $quote->specialRequests;
        foreach ($quote->addons as $line) {
            $this->addons->add(new BookingAddon($this, $line->addon, $line->quantity));
        }
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

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && $this->user->getId() === $user->getId();
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

    public function getOriginalReturnAt(): ?\DateTimeImmutable
    {
        return $this->originalReturnAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /**
     * Billed rental days, as v15 counts them: every started 24 hours.
     */
    public function getDays(): int
    {
        return max(1, (int) ceil(($this->returnAt->getTimestamp() - $this->pickupAt->getTimestamp()) / 86400));
    }

    public function getPickupMethod(): string
    {
        return $this->pickupMethod;
    }

    public function getPickupLocation(): string
    {
        return $this->pickupLocation;
    }

    public function getDeliveryAddress(): string
    {
        return (string) $this->deliveryAddress;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPromoCode(): string
    {
        return (string) $this->promoCode;
    }

    public function getSubtotal(): int
    {
        return $this->subtotal;
    }

    public function getAddonsTotal(): int
    {
        return $this->addonsTotal;
    }

    public function getDeliveryFee(): int
    {
        return $this->deliveryFee;
    }

    public function getDiscount(): int
    {
        return $this->discount;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getDeposit(): int
    {
        return $this->deposit;
    }

    public function getSpecialRequests(): string
    {
        return (string) $this->specialRequests;
    }

    /**
     * @return Collection<int, BookingAddon>
     */
    public function getAddons(): Collection
    {
        return $this->addons;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
