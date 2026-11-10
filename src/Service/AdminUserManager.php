<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account administration beyond role/status (UserAccessManager): create
 * accounts of any role, correct details, set a new password, and delete
 * accounts that have no business history.
 */
final class AdminUserManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
        private readonly ReferenceGuard $references,
    ) {
    }

    public function create(User $user, string $plainPassword): void
    {
        $user->setStatus(User::STATUS_ACTIVE)
            ->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->auditLogger->log('user_created', 'user', $user->getId(), ['role' => $user->getRole()]);
    }

    public function update(User $user): void
    {
        $this->entityManager->flush();
        $this->auditLogger->log('user_updated', 'user', $user->getId());
    }

    public function discardChanges(User $user): void
    {
        $this->entityManager->refresh($user);
    }

    /**
     * Also spends any open "forgot password" link. Changing the hash signs the
     * account out of its other sessions (User::isEqualTo()).
     */
    public function resetPassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->clearPasswordReset();
        $this->entityManager->flush();
        $this->auditLogger->log('user_password_reset', 'user', $user->getId());
    }

    /**
     * @throws \DomainException when the account must not be deleted
     */
    public function delete(User $actingAdmin, User $target): void
    {
        $this->assertDeletable($actingAdmin, $target);

        $id = $target->getId();
        $email = $target->getEmail();
        $this->entityManager->remove($target);
        $this->entityManager->flush();
        $this->auditLogger->log('user_deleted', 'user', $id, ['email' => $email]);
    }

    /**
     * @throws \DomainException with the reason the account cannot be deleted
     */
    public function assertDeletable(User $actingAdmin, User $target): void
    {
        if ($target->getId() === $actingAdmin->getId()) {
            throw new \DomainException('You cannot delete your own account.');
        }

        if ($target->isAdmin() && $target->isActive() && $this->userRepository->countActiveAdmins() <= 1) {
            throw new \DomainException('At least one active administrator must remain.');
        }

        // bookings, payments, reviews, inspections and maintenance keep their author
        if ($this->references->isReferenced($target)) {
            throw new \DomainException('This account has bookings, payments, reviews or service records, so it cannot be deleted. Set it to inactive instead.');
        }
    }
}
