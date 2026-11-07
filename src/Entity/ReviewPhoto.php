<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A trip photo attached to a review (v15 review_photos), stored privately.
 */
#[ORM\Entity]
#[ORM\Table(name: 'review_photos')]
class ReviewPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Review $review;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Review $review, string $filename)
    {
        $this->review = $review;
        $this->filename = $filename;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReview(): Review
    {
        return $this->review;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }
}
