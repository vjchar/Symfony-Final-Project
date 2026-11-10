<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * v15 notifications_for_user(): newest first, with the booking loaded.
     *
     * @return list<Notification>
     */
    public function findForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('b')
            ->leftJoin('n.booking', 'b')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnread(User $user): int
    {
        return $this->count(['user' => $user, 'isRead' => false]);
    }

    /**
     * v15 mark_notifications_read().
     */
    public function markAllRead(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':read')
            ->where('n.user = :user AND n.isRead = :unread')
            ->setParameter('read', true)
            ->setParameter('unread', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
