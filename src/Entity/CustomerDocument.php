<?php

namespace App\Entity;

use App\Repository\CustomerDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the two rental documents a customer submits for verification
 * (v15 customer_documents): one row per customer and document type, replaced
 * in place when the customer uploads a new file.
 */
#[ORM\Entity(repositoryClass: CustomerDocumentRepository::class)]
#[ORM\Table(name: 'customer_documents')]
#[ORM\UniqueConstraint(name: 'uniq_documents_user_type', columns: ['user_id', 'document_type'])]
#[ORM\Index(name: 'idx_documents_user_status', columns: ['user_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class CustomerDocument
{
    public const TYPE_DRIVERS_LICENSE = 'drivers_license';
    public const TYPE_GOVERNMENT_ID = 'government_id';
    // v15 documents.php: label, icon, description
    public const TYPES = [
        self::TYPE_DRIVERS_LICENSE => ['Driver’s License', 'bi-person-vcard', 'Required for the primary driver. It must still be valid on the return date.'],
        self::TYPE_GOVERNMENT_ID => ['Government-issued ID', 'bi-card-heading', 'Use a clear passport, national ID, or another accepted government ID.'],
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $documentType;

    #[ORM\Column(length: 120, nullable: true, options: ['default' => ''])]
    private ?string $documentNumber = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiryDate = null;

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'verified_by', onDelete: 'SET NULL')]
    private ?User $verifiedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $documentType)
    {
        $this->user = $user;
        $this->documentType = $documentType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 upsert_customer_document(): a new file goes back to pending review.
     */
    public function replace(string $documentNumber, ?\DateTimeImmutable $expiryDate, string $filename): void
    {
        $this->documentNumber = $documentNumber;
        $this->expiryDate = $expiryDate;
        $this->filename = $filename;
        $this->status = self::STATUS_PENDING;
        $this->adminNotes = null;
        $this->verifiedBy = null;
        $this->verifiedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * v15 review_customer_document(): an administrator approves or rejects the file.
     */
    public function review(string $status, string $notes, User $admin): void
    {
        if (!\in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Choose approve or reject.');
        }
        $this->status = $status;
        $this->adminNotes = mb_substr(trim($notes), 0, 2000);
        $this->verifiedBy = $admin;
        $this->verifiedAt = new \DateTimeImmutable();
    }

    /**
     * Approved and, for a driver's license, still valid (v15 booking_requirements()).
     */
    public function isVerified(): bool
    {
        return self::STATUS_APPROVED === $this->getEffectiveStatus();
    }

    /**
     * The status the customer sees: an approved driver's license past its expiry date counts as expired.
     */
    public function getEffectiveStatus(?\DateTimeImmutable $today = null): string
    {
        $today ??= new \DateTimeImmutable('today');
        if (self::TYPE_DRIVERS_LICENSE === $this->documentType && null !== $this->expiryDate && $this->expiryDate <= $today) {
            return 'expired';
        }

        return $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getLabel(): string
    {
        return self::TYPES[$this->documentType][0] ?? ucwords(str_replace('_', ' ', $this->documentType));
    }

    public function getDocumentNumber(): string
    {
        return (string) $this->documentNumber;
    }

    /**
     * The last four characters, as v15 shows the number back to the customer.
     */
    public function getMaskedNumber(): string
    {
        return '•••• '.mb_substr($this->getDocumentNumber(), -4);
    }

    public function getExpiryDate(): ?\DateTimeImmutable
    {
        return $this->expiryDate;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAdminNotes(): string
    {
        return (string) $this->adminNotes;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
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
