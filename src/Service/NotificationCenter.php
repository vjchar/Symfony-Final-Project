<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 notify_user() and notify_admins(). The notifications are persisted
 * here and saved with the caller's next flush.
 */
final class NotificationCenter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
    ) {
    }

    public function notifyUser(User $user, string $title, string $message, string $type = 'system', ?Booking $booking = null): void
    {
        $this->entityManager->persist(new Notification($user, $title, $message, $type, $booking));
    }

    /**
     * The same operational update for every active administrator.
     */
    public function notifyAdmins(string $title, string $message, string $type = 'system', ?Booking $booking = null): void
    {
        foreach ($this->users->findBy(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]) as $admin) {
            $this->notifyUser($admin, $title, $message, $type, $booking);
        }
    }
}
