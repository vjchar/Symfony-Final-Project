<?php

namespace App\Entity;

use App\Repository\RentalSettlementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The final bill after a vehicle comes back (v15 rental_settlements): the
 * verified deposit held, minus damage, fuel, late and other charges, gives
 * either a deposit refund or a balance the customer still owes. One per booking.
 */
#[ORM\Entity(repositoryClass: RentalSettlementRepository::class)]
#[ORM\Table(name: 'rental_settlements')]
#[ORM\Index(name: 'idx_settlements_status', columns: ['status', 'finalized_at'])]
#[ORM\HasLifecycleCallbacks]
class RentalSettlement
{
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_BALANCE_DUE = 'balance_due';
    public const STATUS_REFUND_PENDING = 'refund_pending';
    public const STATUS_SETTLED = 'settled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\Column(options: ['default' => 0])]
    private int $depositPaid;

    #[ORM\Column(options: ['default' => 0])]
    private int $damageCharges;

    #[ORM\Column(options: ['default' => 0])]
    private int $fuelCharges;

    #[ORM\Column(options: ['default' => 0])]
    private int $lateCharges;

    #[ORM\Column(options: ['default' => 0])]
    private int $otherCharges;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalDeductions;

    #[ORM\Column(options: ['default' => 0])]
    private int $depositRefundAmount;

    #[ORM\Column(options: ['default' => 0])]
    private int $outstandingBalance;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_FINALIZED])]
    private string $status = self::STATUS_FINALIZED;

    #[ORM\Column]
    private \DateTimeImmutable $finalizedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'finalized_by', nullable: false)]
    private User $finalizedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * v15 finalize_return_settlement() and rental_settlement_preview(): the
     * totals are worked out here so the amounts always agree.
     */
    public function __construct(
        Booking $booking,
        int $depositPaid,
        int $damageCharges,
        int $fuelCharges,
        int $lateCharges,
        int $otherCharges,
        User $finalizedBy,
        string $notes = '',
    ) {
        $this->booking = $booking;
        $this->depositPaid = max(0, $depositPaid);
        $this->damageCharges = max(0, $damageCharges);
        $this->fuelCharges = max(0, $fuelCharges);
        $this->lateCharges = max(0, $lateCharges);
        $this->otherCharges = max(0, $otherCharges);
        $this->totalDeductions = $this->damageCharges + $this->fuelCharges + $this->lateCharges + $this->otherCharges;
        $this->depositRefundAmount = max(0, $this->depositPaid - $this->totalDeductions);
        $this->outstandingBalance = max(0, $this->totalDeductions - $this->depositPaid);
        $this->status = match (true) {
            $this->outstandingBalance > 0 => self::STATUS_BALANCE_DUE,
            $this->depositRefundAmount > 0 => self::STATUS_REFUND_PENDING,
            default => self::STATUS_SETTLED,
        };
        $this->finalizedBy = $finalizedBy;
        $this->notes = mb_substr(trim($notes), 0, 3000);
        $this->finalizedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 refresh_rental_settlement_status(), run after a deposit refund or a
     * return-charge payment changes.
     *
     * @param int  $pendingRefunds   open (pending/approved/processing) security-deposit refunds for this settlement
     * @param int  $refundedAmount   security-deposit refunds already paid out for this settlement
     * @param bool $extraChargeDue   whether the booking still has an unpaid return charge
     */
    public function refreshStatus(int $pendingRefunds, int $refundedAmount, bool $extraChargeDue): void
    {
        if ($this->outstandingBalance > 0 && $extraChargeDue) {
            $this->status = self::STATUS_BALANCE_DUE;
        } elseif ($this->depositRefundAmount > 0) {
            if ($pendingRefunds > 0) {
                $this->status = self::STATUS_REFUND_PENDING;
            } elseif ($refundedAmount >= $this->depositRefundAmount) {
                $this->status = self::STATUS_SETTLED;
            }
        } else {
            $this->status = self::STATUS_SETTLED;
        }
    }

    public function isSettled(): bool
    {
        return self::STATUS_SETTLED === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getDepositPaid(): int
    {
        return $this->depositPaid;
    }

    public function getDamageCharges(): int
    {
        return $this->damageCharges;
    }

    public function getFuelCharges(): int
    {
        return $this->fuelCharges;
    }

    public function getLateCharges(): int
    {
        return $this->lateCharges;
    }

    public function getOtherCharges(): int
    {
        return $this->otherCharges;
    }

    public function getTotalDeductions(): int
    {
        return $this->totalDeductions;
    }

    public function getDepositRefundAmount(): int
    {
        return $this->depositRefundAmount;
    }

    public function getOutstandingBalance(): int
    {
        return $this->outstandingBalance;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFinalizedAt(): \DateTimeImmutable
    {
        return $this->finalizedAt;
    }

    public function getFinalizedBy(): User
    {
        return $this->finalizedBy;
    }

    public function getNotes(): string
    {
        return (string) $this->notes;
    }
}
