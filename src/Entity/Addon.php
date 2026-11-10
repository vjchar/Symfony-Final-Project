<?php

namespace App\Entity;

use App\Repository\AddonRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AddonRepository::class)]
#[ORM\Table(name: 'addons')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('addonKey', message: 'That add-on key is already in use.')]
class Addon
{
    public const DEFAULT_ICON = 'bi-plus-circle';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank(message: 'Enter a key and display name.')]
    #[Assert\Length(max: 80, maxMessage: 'Enter a key and display name.')]
    private ?string $addonKey = null;

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank(message: 'Enter a key and display name.')]
    private ?string $name = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Enter a valid add-on price.')]
    #[Assert\PositiveOrZero(message: 'Enter a valid add-on price.')]
    private ?int $price = 0;

    // Add-ons are charged once per booking ("rental"), as in the Version 15 site.
    #[ORM\Column(length: 20, options: ['default' => 'rental'])]
    private string $billing = 'rental';

    #[ORM\Column(length: 80)]
    private string $icon = self::DEFAULT_ICON;

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

    public function getAddonKey(): ?string
    {
        return $this->addonKey;
    }

    /**
     * Normalised like v15 save_addon(): anything other than letters, digits and hyphens becomes a hyphen.
     */
    public function setAddonKey(?string $addonKey): static
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9-]/i', '-', trim((string) $addonKey)));
        $this->addonKey = '' === $key ? null : $key;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $name = mb_substr(trim((string) $name), 0, 140);
        $this->name = '' === $name ? null : $name;

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

    public function getBilling(): string
    {
        return $this->billing;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * Falls back to bi-plus-circle for an empty or over-long icon class, like v15.
     */
    public function setIcon(?string $icon): static
    {
        $icon = (string) preg_replace('/[^a-z0-9-]/i', '', trim((string) $icon));
        $this->icon = '' === $icon || strlen($icon) > 80 ? self::DEFAULT_ICON : $icon;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
