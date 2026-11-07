<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An in-application update for one account (v15 notifications), optionally
 * about one booking.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_user_read', columns: ['user_id', 'is_read', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Booking $booking;

    #[ORM\Column(length: 40, options: ['default' => 'system'])]
    private string $type;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $title, string $message, string $type = 'system', ?Booking $booking = null)
    {
        $this->user = $user;
        $this->title = mb_substr($title, 0, 160);
        $this->message = $message;
        $this->type = mb_substr($type, 0, 40);
        $this->booking = $booking;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The icon v15 notifications.php shows for each type.
     */
    public function getIcon(): string
    {
        return match ($this->type) {
            'payment' => 'bi-credit-card',
            'document' => 'bi-person-vcard',
            'rental' => 'bi-key',
            'booking' => 'bi-calendar2-check',
            default => 'bi-bell',
        };
    }
}
