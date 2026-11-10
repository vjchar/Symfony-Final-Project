<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 admin_update_user_access(): change an account's role and status while
 * always keeping at least one active administrator.
 */
final class UserAccessManager
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws \DomainException with a v15 message when the change is not allowed
     */
    public function update(User $actingAdmin, int $userId, string $role, string $status): User
    {
        if (!\in_array($role, User::ROLES, true) || !\in_array($status, User::STATUSES, true)) {
            throw new \DomainException('Choose a valid role and account status.');
        }

        $target = $this->userRepository->find($userId) ?? throw new \DomainException('User account not found.');
        $removesAdminAccess = User::ROLE_ADMIN !== $role || User::STATUS_ACTIVE !== $status;

        if ($target === $actingAdmin && $removesAdminAccess) {
            throw new \DomainException('You cannot remove or deactivate your own administrator access.');
        }

        if ($target->isAdmin() && $target->isActive() && $removesAdminAccess && $this->userRepository->countActiveAdmins() <= 1) {
            throw new \DomainException('At least one active administrator must remain.');
        }

        $target->setRole($role)->setStatus($status);
        $this->entityManager->flush();
        $this->auditLogger->log('user_access_updated', 'user', $target->getId(), ['role' => $role, 'status' => $status]);

        return $target;
    }
}
