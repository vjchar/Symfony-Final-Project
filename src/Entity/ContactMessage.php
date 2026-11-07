<?php

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A support enquiry from the v15 contact page (contact_messages table).
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_messages')]
#[ORM\HasLifecycleCallbacks]
class ContactMessage
{
    public const STATUSES = ['new', 'read', 'closed'];

    public const SUBJECTS = ['New booking', 'Vehicle information', 'Corporate rental', 'Existing rental support', 'Other inquiry'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Enter your full name.')]
    #[Assert\Length(min: 2, max: 120, minMessage: 'Enter your full name.', maxMessage: 'Enter your full name.')]
    private ?string $name = null;

    #[ORM\Column(length: 190)]
    #[Assert\NotBlank(message: 'Enter a valid email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true, options: ['default' => ''])]
    #[Assert\Length(max: 40)]
    private ?string $phone = '';

    #[ORM\Column(length: 180)]
    #[Assert\Choice(choices: self::SUBJECTS, message: 'Choose a valid support topic.')]
    #[Assert\NotBlank(message: 'Choose a valid support topic.')]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Enter a message between 20 and 5,000 characters.')]
    #[Assert\Length(min: 20, max: 5000, minMessage: 'Enter a message between 20 and 5,000 characters.', maxMessage: 'Enter a message between 20 and 5,000 characters.')]
    private ?string $message = null;

    #[ORM\Column(length: 20, options: ['default' => 'new'])]
    private string $status = 'new';

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim($name);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : strtolower(trim($email));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = trim((string) $phone);

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = null === $message ? null : trim($message);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * v15 update_contact_message_status().
     */
    public function setStatus(string $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \DomainException('Choose a valid message status.');
        }
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
