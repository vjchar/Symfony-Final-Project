<?php

namespace App\Entity;

use App\Repository\VehicleRepository;
use App\Validator\NoUpcomingBookingsWhenOffline;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'vehicles')]
#[ORM\Index(name: 'idx_vehicles_category', columns: ['category', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('slug', message: 'That vehicle slug is already used.')]
#[NoUpcomingBookingsWhenOffline]
class Vehicle
{
    // Choice lists and ranges below mirror v15 includes/admin-vehicle-service.php.
    public const CATEGORIES = ['Luxury', 'SUV', 'Pickup', 'Van', 'EV'];
    public const TRANSMISSIONS = ['Automatic', 'Manual'];
    public const FUELS = ['Gasoline', 'Diesel', 'Electric', 'Hybrid'];
    public const AVAILABILITY_STATUSES = ['available', 'maintenance', 'unavailable'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140, unique: true)]
    #[Assert\NotBlank(message: 'Use a lowercase URL slug with letters, numbers, and hyphens.')]
    #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Use a lowercase URL slug with letters, numbers, and hyphens.')]
    #[Assert\Length(max: 140)]
    private ?string $slug = null;

    #[ORM\Column(length: 180)]
    #[Assert\Length(min: 2, max: 180, minMessage: 'Enter a valid vehicle name and brand.', maxMessage: 'Enter a valid vehicle name and brand.')]
    #[Assert\NotBlank(message: 'Enter a valid vehicle name and brand.')]
    private ?string $name = null;

    #[ORM\Column(length: 100)]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Enter a valid vehicle name and brand.', maxMessage: 'Enter a valid vehicle name and brand.')]
    #[Assert\NotBlank(message: 'Enter a valid vehicle name and brand.')]
    private ?string $brand = null;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::CATEGORIES, message: 'Choose a valid category.')]
    #[Assert\NotBlank(message: 'Choose a valid category.')]
    private ?string $category = null;

    // Required, but checked by VehicleType because a pending upload also satisfies it.
    #[ORM\Column(length: 255)]
    private ?string $image = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Enter valid numeric vehicle values.')]
    #[Assert\Range(min: 500, max: 100000, notInRangeMessage: 'Enter valid numeric vehicle values.')]
    private ?int $price = null;

    #[ORM\Column(options: ['default' => 5000])]
    #[Assert\Range(min: 0, max: 100000, notInRangeMessage: 'Enter valid numeric vehicle values.')]
    private ?int $deposit = 5000;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Enter valid numeric vehicle values.')]
    #[Assert\Range(min: 1, max: 30, notInRangeMessage: 'Enter valid numeric vehicle values.')]
    private ?int $seats = 5;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Enter valid numeric vehicle values.')]
    #[Assert\Range(min: 1, max: 8, notInRangeMessage: 'Enter valid numeric vehicle values.')]
    private ?int $doors = 4;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Complete the luggage, overview, and description fields.')]
    private ?string $luggage = '3 bags';

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::TRANSMISSIONS, message: 'Choose a valid transmission.')]
    private ?string $transmission = 'Automatic';

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::FUELS, message: 'Choose a valid fuel type.')]
    private ?string $fuel = 'Gasoline';

    #[ORM\Column(options: ['default' => 200])]
    #[Assert\Range(min: 0, max: 2000, notInRangeMessage: 'Enter valid numeric vehicle values.')]
    private ?int $dailyKm = 200;

    #[ORM\Column(length: 255)]
    #[Assert\Length(min: 5, max: 255, minMessage: 'Complete the luggage, overview, and description fields.')]
    #[Assert\NotBlank(message: 'Complete the luggage, overview, and description fields.')]
    private ?string $overviewTitle = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\Length(min: 30, minMessage: 'Complete the luggage, overview, and description fields.')]
    #[Assert\NotBlank(message: 'Complete the luggage, overview, and description fields.')]
    private ?string $overview = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\Length(min: 30, minMessage: 'Complete the luggage, overview, and description fields.')]
    #[Assert\NotBlank(message: 'Complete the luggage, overview, and description fields.')]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'Add at least one inclusion and one feature.')]
    private array $inclusions = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'Add at least one inclusion and one feature.')]
    private array $features = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 30, options: ['default' => 'available'])]
    #[Assert\Choice(choices: self::AVAILABILITY_STATUSES, message: 'Choose a valid fleet status.')]
    private string $availabilityStatus = 'available';

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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = null === $slug ? null : strtolower(trim($slug));

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getDeposit(): ?int
    {
        return $this->deposit;
    }

    public function setDeposit(?int $deposit): static
    {
        $this->deposit = $deposit;

        return $this;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(?int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getDoors(): ?int
    {
        return $this->doors;
    }

    public function setDoors(?int $doors): static
    {
        $this->doors = $doors;

        return $this;
    }

    public function getLuggage(): ?string
    {
        return $this->luggage;
    }

    public function setLuggage(?string $luggage): static
    {
        $this->luggage = $luggage;

        return $this;
    }

    public function getTransmission(): ?string
    {
        return $this->transmission;
    }

    public function setTransmission(?string $transmission): static
    {
        $this->transmission = $transmission;

        return $this;
    }

    public function getFuel(): ?string
    {
        return $this->fuel;
    }

    public function setFuel(?string $fuel): static
    {
        $this->fuel = $fuel;

        return $this;
    }

    public function getDailyKm(): ?int
    {
        return $this->dailyKm;
    }

    public function setDailyKm(?int $dailyKm): static
    {
        $this->dailyKm = $dailyKm;

        return $this;
    }

    public function getOverviewTitle(): ?string
    {
        return $this->overviewTitle;
    }

    public function setOverviewTitle(?string $overviewTitle): static
    {
        $this->overviewTitle = $overviewTitle;

        return $this;
    }

    public function getOverview(): ?string
    {
        return $this->overview;
    }

    public function setOverview(?string $overview): static
    {
        $this->overview = $overview;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** @return list<string> */
    public function getInclusions(): array
    {
        return $this->inclusions;
    }

    /** @param list<string> $inclusions */
    public function setInclusions(array $inclusions): static
    {
        $this->inclusions = $inclusions;

        return $this;
    }

    /** @return list<string> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @param list<string> $features */
    public function setFeatures(array $features): static
    {
        $this->features = $features;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getAvailabilityStatus(): string
    {
        return $this->availabilityStatus;
    }

    public function setAvailabilityStatus(string $availabilityStatus): static
    {
        $this->availabilityStatus = $availabilityStatus;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
