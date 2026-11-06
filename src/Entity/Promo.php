<?php

namespace App\Entity;

use App\Repository\PromoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A discount code in the v15 promos table. Validation messages follow v15 save_promo().
 */
#[ORM\Entity(repositoryClass: PromoRepository::class)]
#[ORM\Table(name: 'promos')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('code', message: 'The promotion could not be saved. The code may already be in use.')]
class Promo
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    #[Assert\NotBlank(message: 'Enter a promotion code using letters, numbers, underscores, or hyphens.')]
    #[Assert\Length(max: 40, maxMessage: 'Enter a promotion code using letters, numbers, underscores, or hyphens.')]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Enter a description between 3 and 255 characters.')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Enter a description between 3 and 255 characters.', maxMessage: 'Enter a description between 3 and 255 characters.')]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES, message: 'Choose a valid discount type.')]
    private ?string $discountType = self::TYPE_PERCENT;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Enter a valid discount value.')]
    #[Assert\Range(min: 1, max: 100000, notInRangeMessage: 'Enter a valid discount value.')]
    #[Assert\Expression("this.getDiscountType() !== 'percent' or value === null or value <= 100", message: 'Enter a valid discount value.')]
    private ?int $discountValue = 10;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsAt', message: 'End date must be after the start date.')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 1000000, notInRangeMessage: 'Maximum uses must be a positive whole number or blank.')]
    private ?int $maxUses = null;

    // counted by bookings; a used code can only be deactivated, never deleted
    #[ORM\Column(options: ['default' => 0])]
    private int $usedCount = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Upper-cased, keeping only letters, numbers, underscores and hyphens, like v15.
     */
    public function setCode(?string $code): static
    {
        $this->code = strtoupper((string) preg_replace('/[^A-Z0-9_-]/i', '', trim((string) $code)));

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

    public function getDiscountType(): ?string
    {
        return $this->discountType;
    }

    public function setDiscountType(?string $discountType): static
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function isPercent(): bool
    {
        return self::TYPE_PERCENT === $this->discountType;
    }

    public function getDiscountValue(): ?int
    {
        return $this->discountValue;
    }

    public function setDiscountValue(?int $discountValue): static
    {
        $this->discountValue = $discountValue;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    /**
     * A code starts at the beginning of its first day.
     */
    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt?->setTime(0, 0);

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * A code stays valid until the end of its last day.
     */
    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt?->setTime(23, 59, 59);

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): static
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    /**
     * v15 apply_promo(): never more than the rental subtotal.
     */
    public function discountFor(int $subtotal): int
    {
        return $this->isPercent()
            ? min($subtotal, (int) round($subtotal * ((int) $this->discountValue / 100)))
            : min($subtotal, (int) $this->discountValue);
    }

    public function isDeletable(): bool
    {
        return 0 === $this->usedCount;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
