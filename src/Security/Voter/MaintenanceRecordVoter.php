<?php

namespace App\Security\Voter;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with a maintenance record: administrators everything, staff
 * may read every record but only change or delete the ones they created.
 *
 * @extends Voter<string, MaintenanceRecord>
 */
final class MaintenanceRecordVoter extends Voter
{
    public const VIEW = 'MAINTENANCE_VIEW';
    public const EDIT = 'MAINTENANCE_EDIT';
    public const DELETE = 'MAINTENANCE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof MaintenanceRecord;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->isStaff()) {
            $vote?->addReason('Only staff and administrators can manage maintenance records.');

            return false;
        }

        if (self::VIEW === $attribute) {
            return true;
        }

        $owns = null !== $user->getId() && $subject->getCreatedBy()?->getId() === $user->getId();
        if (!$owns) {
            $vote?->addReason('Staff can only change maintenance records they created.');
        }

        return $owns;
    }
}
