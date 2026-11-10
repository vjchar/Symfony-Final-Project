<?php

namespace App\Entity;

use App\Repository\RentalInspectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The pickup (checkout) or return (checkin) check of a rented vehicle
 * (v15 rental_inspections). A booking has at most one of each; recording
 * again replaces the values, like v15 save_inspection().
 */
#[ORM\Entity(repositoryClass: RentalInspectionRepository::class)]
#[ORM\Table(name: 'rental_inspections')]
#[ORM\UniqueConstraint(name: 'uniq_inspections_booking_type', columns: ['booking_id', 'inspection_type'])]
#[ORM\HasLifecycleCallbacks]
class RentalInspection
{
    public const INVALID = 'Enter valid inspection values.';

    public const TYPE_CHECKOUT = 'checkout';
    public const TYPE_CHECKIN = 'checkin';
    public const TYPES = [self::TYPE_CHECKOUT, self::TYPE_CHECKIN];

    public const CONDITIONS = ['excellent', 'good', 'fair', 'damaged'];

    public const MAX_ODOMETER = 2000000;
    public const MAX_EXTRA_CHARGES = 1000000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\Column(length: 20)]
    private string $inspectionType;

    #[ORM\Column(options: ['default' => 0])]
    private int $odometer = 0;

    #[ORM\Column(options: ['default' => 100])]
    private int $fuelPercent = 100;

    #[ORM\Column(length: 40, options: ['default' => 'good'])]
    private string $bodyCondition = 'good';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $damageNotes = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $extraCharges = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by', nullable: false)]
    private User $recordedBy;

    #[ORM\Column]
    private \DateTimeImmutable $inspectedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param self::TYPE_* $type
     */
    public function __construct(Booking $booking, string $type, User $recordedBy)
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Choose a valid inspection type.');
        }

        $this->booking = $booking;
        $this->inspectionType = $type;
        $this->recordedBy = $recordedBy;
        $this->inspectedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 save_inspection(): checks the ranges and stores the readings. Only a
     * return inspection carries extra charges; a pickup one always records 0.
     * (Comparing the return odometer with the pickup one needs both
     * inspections, so the rental desk service does that.)
     *
     * @throws \InvalidArgumentException when a value is out of range
     */
    public function record(
        int $odometer,
        int $fuelPercent,
        string $bodyCondition,
        string $notes,
        string $damageNotes,
        int $extraCharges,
        User $recordedBy,
    ): void {
        if (!$this->isCheckin()) {
            $extraCharges = 0;
        }

        if ($odometer < 0 || $odometer > self::MAX_ODOMETER
            || $fuelPercent < 0 || $fuelPercent > 100
            || $extraCharges < 0 || $extraCharges > self::MAX_EXTRA_CHARGES
            || !\in_array($bodyCondition, self::CONDITIONS, true)) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        $this->odometer = $odometer;
        $this->fuelPercent = $fuelPercent;
        $this->bodyCondition = $bodyCondition;
        $this->notes = mb_substr(trim($notes), 0, 3000);
        $this->damageNotes = mb_substr(trim($damageNotes), 0, 3000);
        $this->extraCharges = $extraCharges;
        $this->recordedBy = $recordedBy;
        $this->inspectedAt = new \DateTimeImmutable();
    }

    public function isCheckin(): bool
    {
        return self::TYPE_CHECKIN === $this->inspectionType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getInspectionType(): string
    {
        return $this->inspectionType;
    }

    public function getOdometer(): int
    {
        return $this->odometer;
    }

    public function getFuelPercent(): int
    {
        return $this->fuelPercent;
    }

    public function getBodyCondition(): string
    {
        return $this->bodyCondition;
    }

    public function getNotes(): string
    {
        return (string) $this->notes;
    }

    public function getDamageNotes(): string
    {
        return (string) $this->damageNotes;
    }

    public function getExtraCharges(): int
    {
        return $this->extraCharges;
    }

    public function getRecordedBy(): User
    {
        return $this->recordedBy;
    }

    public function getInspectedAt(): \DateTimeImmutable
    {
        return $this->inspectedAt;
    }
}
