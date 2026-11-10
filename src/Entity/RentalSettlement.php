<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The final bill after a vehicle comes back (v15 rental_settlements, folded
 * into the settlement_* columns of bookings): the verified deposit held, minus
 * damage, fuel, late and other charges, gives either a deposit refund or a
 * balance the customer still owes. One per booking, so it lives on the booking.
 *
 * A booking that has not been settled yet carries an empty settlement (no
 * finalizedAt); Booking::getSettlement() returns null for it.
 */
#[ORM\Embeddable]
class RentalSettlement
{
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_BALANCE_DUE = 'balance_due';
    public const STATUS_REFUND_PENDING = 'refund_pending';
    public const STATUS_SETTLED = 'settled';

    #[ORM\Column(options: ['default' => 0])]
    private int $depositPaid = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $damageCharges = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $fuelCharges = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $lateCharges = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $otherCharges = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalDeductions = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $depositRefundAmount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $outstandingBalance = 0;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * v15 finalize_return_settlement() and rental_settlement_preview(): the
     * totals are worked out here so the amounts always agree.
     */
    public static function calculate(
        int $depositPaid,
        int $damageCharges,
        int $fuelCharges,
        int $lateCharges,
        int $otherCharges,
        string $notes = '',
    ): self {
        $settlement = new self();
        $settlement->depositPaid = max(0, $depositPaid);
        $settlement->damageCharges = max(0, $damageCharges);
        $settlement->fuelCharges = max(0, $fuelCharges);
        $settlement->lateCharges = max(0, $lateCharges);
        $settlement->otherCharges = max(0, $otherCharges);
        $settlement->totalDeductions = $settlement->damageCharges + $settlement->fuelCharges + $settlement->lateCharges + $settlement->otherCharges;
        $settlement->depositRefundAmount = max(0, $settlement->depositPaid - $settlement->totalDeductions);
        $settlement->outstandingBalance = max(0, $settlement->totalDeductions - $settlement->depositPaid);
        $settlement->status = match (true) {
            $settlement->outstandingBalance > 0 => self::STATUS_BALANCE_DUE,
            $settlement->depositRefundAmount > 0 => self::STATUS_REFUND_PENDING,
            default => self::STATUS_SETTLED,
        };
        $settlement->notes = mb_substr(trim($notes), 0, 3000);
        $settlement->finalizedAt = new \DateTimeImmutable();

        return $settlement;
    }

    public function isFinalized(): bool
    {
        return null !== $this->finalizedAt;
    }

    /**
     * v15 refresh_rental_settlement_status(), run after a deposit refund or a
     * return-charge payment changes.
     *
     * @param int  $pendingRefunds open (pending/approved/processing) security-deposit refunds of the booking
     * @param int  $refundedAmount security-deposit refunds of the booking already paid out
     * @param bool $extraChargeDue whether the booking still has an unpaid return charge
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
        return (string) $this->status;
    }

    public function getFinalizedAt(): ?\DateTimeImmutable
    {
        return $this->finalizedAt;
    }

    public function getNotes(): string
    {
        return (string) $this->notes;
    }
}
