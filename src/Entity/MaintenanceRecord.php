<?php

namespace App\Entity;

use App\Repository\MaintenanceRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A service event in the v15 maintenance_records table. v15 create_maintenance_record()
 * answers every invalid field with one message, so every constraint uses it.
 * (The v15 booking_id column arrives with bookings.)
 */
#[ORM\Entity(repositoryClass: MaintenanceRecordRepository::class)]
#[ORM\Table(name: 'maintenance_records')]
#[ORM\Index(name: 'idx_maintenance_vehicle_dates', columns: ['vehicle_id', 'starts_at', 'ends_at', 'status'])]
#[ORM\HasLifecycleCallbacks]
class MaintenanceRecord
{
    public const INVALID = 'Complete all maintenance fields with valid values.';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: self::INVALID)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: self::INVALID)]
    #[Assert\Length(min: 3, max: 160, minMessage: self::INVALID, maxMessage: self::INVALID)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: self::INVALID)]
    #[Assert\Length(min: 5, max: 3000, minMessage: self::INVALID, maxMessage: self::INVALID)]
    private ?string $description = null;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_SCHEDULED])]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column]
    #[Assert\NotNull(message: self::INVALID)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\NotNull(message: self::INVALID)]
    #[Assert\Range(min: 0, max: 10000000, notInRangeMessage: self::INVALID)]
    private ?int $cost = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = null === $title ? null : trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description ? null : trim($description);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * v15 update_maintenance_status(): a vehicle in service leaves online
     * availability, and comes back when the work is completed or cancelled.
     */
    public function changeStatus(string $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \DomainException('Choose a valid maintenance update.');
        }

        $finished = \in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
        $this->status = $status;
        $this->endsAt = $finished ? new \DateTimeImmutable() : null;

        if (self::STATUS_IN_PROGRESS === $status) {
            $this->vehicle->setAvailabilityStatus('maintenance');
        } elseif ($finished) {
            // v15 sync_vehicle_status(): an inactive vehicle stays unavailable
            $this->vehicle->setAvailabilityStatus($this->vehicle->isActive() ? 'available' : 'unavailable');
        }

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getCost(): ?int
    {
        return $this->cost;
    }

    public function setCost(?int $cost): static
    {
        $this->cost = $cost;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
