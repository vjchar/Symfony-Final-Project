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
 * approved modification re-prices it, and an extension or an approved early
 * return moves its return and re-prices it. The
 * rental desk moves it on: ready → active (checkout) → returned (check-in) →
 * completed, stamping v15's ready_at, started_at, returned_at and completed_at.
 * An administrator can also reject a pending booking, cancel one before
 * pickup or mark an uncollected one as a no-show; an unpaid pending booking
 * expires on its own (RentalSweeper).
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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $noShowAt = null;

    // set once the customer and the desk were told the rental is overdue; cleared when the return moves
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $overdueNotifiedAt = null;

    // set once the desk was told the vehicle was not collected at pickup
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $missedPickupNotifiedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    /** The return settlement, stored in the settlement_* columns (v15 rental_settlements). */
    #[ORM\Embedded(class: RentalSettlement::class, columnPrefix: 'settlement_')]
    private RentalSettlement $settlement;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'settlement_finalized_by')]
    private ?User $settlementFinalizedBy = null;

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
        $this->settlement = new RentalSettlement();
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
     * v15 finalize_return_settlement(): records the final bill once.
     *
     * @throws \DomainException when the booking already has one
     */
    public function finalizeSettlement(int $depositPaid, int $damageCharges, int $fuelCharges, int $lateCharges, int $otherCharges, User $admin, string $notes = ''): RentalSettlement
    {
        if ($this->settlement->isFinalized()) {
            throw new \DomainException('This rental already has a finalized settlement.');
        }
        $this->settlement = RentalSettlement::calculate($depositPaid, $damageCharges, $fuelCharges, $lateCharges, $otherCharges, $notes);
        $this->settlementFinalizedBy = $admin;

        return $this->settlement;
    }

    /**
     * v15 rental_settlement_for_booking(): null until the return is settled.
     */
    public function getSettlement(): ?RentalSettlement
    {
        return $this->settlement->isFinalized() ? $this->settlement : null;
    }

    public function getSettlementFinalizedBy(): ?User
    {
        return $this->settlementFinalizedBy;
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

    /**
     * v15 update_booking_status(…, 'ready'): the vehicle is prepared for
     * pickup or delivery. RentalDesk checks the requirements first.
     */
    public function markReady(string $notes = ''): void
    {
        $this->moveTo('confirmed', 'ready', 'That booking status transition is not allowed.');
        $this->readyAt = new \DateTimeImmutable();
        $this->setAdminNotes($notes);
    }

    /**
     * v15 checkout_booking(): the vehicle leaves with the customer.
     */
    public function checkOut(): void
    {
        $this->moveTo('ready', 'active', 'Only a ready booking can be checked out.');
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * v15 checkin_booking(): the vehicle is back and inspected.
     */
    public function checkIn(): void
    {
        $this->moveTo('active', 'returned', 'Only an active rental can be checked in.');
        $this->returnedAt = new \DateTimeImmutable();
    }

    /**
     * v15 admin-rentals.php "complete": the settled rental is closed.
     */
    public function complete(string $notes = ''): void
    {
        $this->moveTo('returned', 'completed', 'That booking status transition is not allowed.');
        $this->completedAt = new \DateTimeImmutable();
        $this->setAdminNotes($notes);
    }

    /**
     * v15 review_refund(): refunding a confirmed booking's deposit as a
     * correction sends it back to waiting for payment.
     */
    public function reopenForPayment(): void
    {
        if (!\in_array($this->status, ['confirmed', 'ready'], true)) {
            throw new \DomainException('That booking status transition is not allowed.');
        }
        $this->status = 'pending';
        $this->readyAt = null;
    }

    public function cancel(string $notes = ''): void
    {
        $this->status = 'cancelled';
        $this->cancelledAt = new \DateTimeImmutable();
        $this->setAdminNotes($notes);
    }

    /**
     * An administrator turns down a booking that is still pending.
     */
    public function reject(string $notes): void
    {
        $this->moveTo('pending', 'rejected', 'Only a pending booking can be rejected.');
        $this->rejectedAt = new \DateTimeImmutable();
        $this->setAdminNotes($notes);
    }

    /**
     * The customer never collected (or received) the vehicle after pickup time.
     */
    public function markNoShow(string $notes = ''): void
    {
        if (!\in_array($this->status, ['confirmed', 'ready'], true)) {
            throw new \DomainException('Only a confirmed or ready booking can be marked as a no-show.');
        }
        if ($this->pickupAt > new \DateTimeImmutable()) {
            throw new \DomainException('A booking can only be marked as a no-show after its pickup time.');
        }
        $this->status = 'no_show';
        $this->noShowAt = new \DateTimeImmutable();
        $this->readyAt = null;
        $this->setAdminNotes($notes);
    }

    public function markOverdueNotified(): void
    {
        $this->overdueNotifiedAt = new \DateTimeImmutable();
    }

    public function markMissedPickupNotified(): void
    {
        $this->missedPickupNotifiedAt = new \DateTimeImmutable();
    }

    /**
     * An active rental past its scheduled return.
     */
    public function isOverdue(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return 'active' === $this->status && $this->returnAt < $now;
    }

    /**
     * The booking's own daily rate (subtotal over billed days).
     */
    public function getDailyRate(): int
    {
        return intdiv($this->subtotal, $this->getDays());
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
        $this->changeReturn($returnAt, $subtotal, $addonsTotal, $discount, $total);
    }

    /**
     * An approved early return: the earlier return, re-priced for fewer days.
     *
     * @throws \DomainException unless an active rental moves to an earlier return
     */
    public function shortenTo(\DateTimeImmutable $returnAt, int $subtotal, int $addonsTotal, int $discount, int $total): void
    {
        if ('active' !== $this->status || $returnAt >= $this->returnAt) {
            throw new \DomainException('An early return must be before the current scheduled return of an active rental.');
        }
        $this->changeReturn($returnAt, $subtotal, $addonsTotal, $discount, $total);
    }

    private function changeReturn(\DateTimeImmutable $returnAt, int $subtotal, int $addonsTotal, int $discount, int $total): void
    {
        $this->keepOriginalReturn();
        $this->returnAt = $returnAt;
        $this->overdueNotifiedAt = null;
        $this->subtotal = $subtotal;
        $this->addonsTotal = $addonsTotal;
        $this->discount = $discount;
        $this->total = $total;
    }

    public function isPrePickup(): bool
    {
        return \in_array($this->status, self::PRE_PICKUP_STATUSES, true);
    }

    private function moveTo(string $from, string $to, string $refusal): void
    {
        if ($from !== $this->status) {
            throw new \DomainException($refusal);
        }
        $this->status = $to;
    }

    private function setAdminNotes(string $notes): void
    {
        if ('' !== trim($notes)) {
            $this->adminNotes = mb_substr(trim($notes), 0, 3000);
        }
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

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getRejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function getNoShowAt(): ?\DateTimeImmutable
    {
        return $this->noShowAt;
    }

    public function getOverdueNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->overdueNotifiedAt;
    }

    public function getMissedPickupNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->missedPickupNotifiedAt;
    }

    public function getAdminNotes(): string
    {
        return (string) $this->adminNotes;
    }

    public function isDelivery(): bool
    {
        return 'Vehicle delivery' === $this->pickupMethod;
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
