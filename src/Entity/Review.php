<?php

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A verified-trip review (v15 reviews): one per completed booking, published
 * once an administrator approves it.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews')]
#[ORM\Index(name: 'idx_reviews_vehicle', columns: ['vehicle_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Review
{
    // v15 rate-trip.php rating categories after the overall score
    public const CATEGORIES = [
        'cleanliness' => 'Cleanliness',
        'comfort' => 'Comfort',
        'vehicle_condition' => 'Vehicle condition',
        'pickup_experience' => 'Pickup experience',
        'customer_support' => 'Customer support',
    ];
    public const SCORE_LABELS = [1 => 'Needs improvement', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column]
    private int $overall;

    #[ORM\Column]
    private int $cleanliness;

    #[ORM\Column]
    private int $comfort;

    #[ORM\Column]
    private int $vehicleCondition;

    #[ORM\Column]
    private int $pickupExperience;

    #[ORM\Column]
    private int $customerSupport;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    /** @var Collection<int, ReviewPhoto> */
    #[ORM\OneToMany(targetEntity: ReviewPhoto::class, mappedBy: 'review', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $photos;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{overall: int, cleanliness: int, comfort: int, vehicle_condition: int, pickup_experience: int, customer_support: int} $scores
     */
    public function __construct(Booking $booking, User $user, array $scores, string $title, string $body)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->vehicle = $booking->getVehicle();
        $this->overall = $scores['overall'];
        $this->cleanliness = $scores['cleanliness'];
        $this->comfort = $scores['comfort'];
        $this->vehicleCondition = $scores['vehicle_condition'];
        $this->pickupExperience = $scores['pickup_experience'];
        $this->customerSupport = $scores['customer_support'];
        $this->title = $title;
        $this->body = $body;
        $this->photos = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 moderate_review(); only approved reviews are published.
     */
    public function moderate(string $status): void
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Choose a valid review decision.');
        }
        $this->status = $status;
    }

    public function addPhoto(string $filename): void
    {
        $this->photos->add(new ReviewPhoto($this, $filename));
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

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getOverall(): int
    {
        return $this->overall;
    }

    public function getCleanliness(): int
    {
        return $this->cleanliness;
    }

    public function getComfort(): int
    {
        return $this->comfort;
    }

    public function getVehicleCondition(): int
    {
        return $this->vehicleCondition;
    }

    public function getPickupExperience(): int
    {
        return $this->pickupExperience;
    }

    public function getCustomerSupport(): int
    {
        return $this->customerSupport;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return Collection<int, ReviewPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
