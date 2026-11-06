<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The add-on columns v15 copies onto booking_addons and booking_draft_addons:
 * name and price are frozen at the moment the line was priced.
 */
#[ORM\MappedSuperclass]
abstract class AddonLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Addon $addon;

    #[ORM\Column(length: 140)]
    private string $addonName;

    #[ORM\Column]
    private int $unitPrice;

    #[ORM\Column(length: 20)]
    private string $billing;

    #[ORM\Column(options: ['default' => 1])]
    private int $quantity;

    #[ORM\Column]
    private int $lineTotal;

    public function __construct(Addon $addon, int $quantity)
    {
        $this->addon = $addon;
        $this->addonName = (string) $addon->getName();
        $this->unitPrice = (int) $addon->getPrice();
        $this->billing = $addon->getBilling();
        $this->quantity = $quantity;
        $this->lineTotal = $this->unitPrice * $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddon(): Addon
    {
        return $this->addon;
    }

    public function getAddonName(): string
    {
        return $this->addonName;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getBilling(): string
    {
        return $this->billing;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getLineTotal(): int
    {
        return $this->lineTotal;
    }
}
