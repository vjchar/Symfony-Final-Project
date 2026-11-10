<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account in the v15 users table. Validation messages follow v15 register_user().
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'role', columns: ['role'])]
#[ORM\HasLifecycleCallbacks]
// v15 words the duplicate-email error differently on register.php and profile.php.
#[UniqueEntity('email', message: 'An account already uses that email address.', groups: ['registration'])]
#[UniqueEntity('email', message: 'That email is already registered.', groups: ['profile'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_STAFF = 'staff';
    public const ROLE_ADMIN = 'admin';
    public const ROLES = [self::ROLE_CUSTOMER, self::ROLE_STAFF, self::ROLE_ADMIN];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // v15 enum('customer','admin') plus 'staff'; mapped to Symfony roles in getRoles().
    #[ORM\Column(length: 20, options: ['default' => self::ROLE_CUSTOMER])]
    private string $role = self::ROLE_CUSTOMER;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Enter your full name.')]
    #[Assert\Length(min: 2, max: 120, minMessage: 'Enter your full name.', maxMessage: 'Enter your full name.')]
    private ?string $name = null;

    #[ORM\Column(length: 190, unique: true)]
    #[Assert\NotBlank(message: 'Enter a valid email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 190, maxMessage: 'Enter a valid email address.')]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true, options: ['default' => ''])]
    #[Assert\Regex('/^[0-9+()\-\s]{7,40}$/', message: 'Enter a valid phone number.')]
    private ?string $phone = '';

    #[ORM\Column(name: 'password_hash')]
    private ?string $password = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * The open password reset (v15 password_resets, folded into users): only
     * the sha256 of the emailed token is stored, and a new request replaces it.
     */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $resetTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetExpiresAt = null;

    /** Kept after the reset is used, for the one-request-per-15-minutes rule. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetRequestedAt = null;

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function isAdmin(): bool
    {
        return self::ROLE_ADMIN === $this->role;
    }

    public function isStaff(): bool
    {
        return self::ROLE_STAFF === $this->role;
    }

    public function isCustomer(): bool
    {
        return self::ROLE_CUSTOMER === $this->role;
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

    public function getFirstName(): string
    {
        return explode(' ', trim((string) $this->name))[0];
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

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return match ($this->role) {
            self::ROLE_ADMIN => ['ROLE_USER', 'ROLE_ADMIN'],
            self::ROLE_STAFF => ['ROLE_USER', 'ROLE_STAFF'],
            default => ['ROLE_USER', 'ROLE_CUSTOMER'],
        };
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startPasswordReset(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->resetTokenHash = $tokenHash;
        $this->resetExpiresAt = $expiresAt;
        $this->resetRequestedAt = new \DateTimeImmutable();
    }

    /**
     * Spends the open reset, so its link stops working.
     */
    public function clearPasswordReset(): void
    {
        $this->resetTokenHash = null;
        $this->resetExpiresAt = null;
    }

    public function getResetTokenHash(): ?string
    {
        return $this->resetTokenHash;
    }

    public function getResetExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetExpiresAt;
    }

    public function hasPasswordResetRequestSince(\DateTimeImmutable $since): bool
    {
        return null !== $this->resetRequestedAt && $this->resetRequestedAt >= $since;
    }

    /**
     * Called on every request with the account freshly loaded from the database
     * ($user) and the copy kept in the session ($this). Returning false signs the
     * visitor out, so a deactivated account loses access immediately instead of
     * when its session expires. The other checks are the ones Symfony makes for
     * users that are not equatable: identifier, roles and password.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || !$user->isActive()) {
            return false;
        }

        if ($this->getUserIdentifier() !== $user->getUserIdentifier() || $this->getRoles() !== $user->getRoles()) {
            return false;
        }

        // the session copy holds the CRC32C of the hash (see __serialize())
        $current = (string) $user->getPassword();

        return $this->password === $current || $this->password === hash('crc32c', $current);
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);
        $data["\0".self::class."\0resetTokenHash"] = null;

        return $data;
    }
}
