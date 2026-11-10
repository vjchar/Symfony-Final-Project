<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One add-on line of a saved booking draft (v15 booking_draft_addons).
 */
#[ORM\Entity]
#[ORM\Table(name: 'booking_draft_addons')]
#[ORM\UniqueConstraint(name: 'draft_addon_unique', columns: ['draft_id', 'addon_id'])]
class BookingDraftAddon extends AddonLine
{
    #[ORM\ManyToOne(inversedBy: 'addons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BookingDraft $draft;

    public function __construct(BookingDraft $draft, Addon $addon, int $quantity)
    {
        parent::__construct($addon, $quantity);
        $this->draft = $draft;
    }

    public function getDraft(): BookingDraft
    {
        return $this->draft;
    }
}
