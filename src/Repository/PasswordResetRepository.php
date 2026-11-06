<?php

namespace App\Repository;

use App\Entity\PasswordReset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordReset>
 */
class PasswordResetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordReset::class);
    }

    public function hasRequestSince(User $user, \DateTimeImmutable $since): bool
    {
        return (bool) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.user = :user')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * v15 password_reset_record(): an unused, unexpired reset of an active account.
     */
    public function findUsable(string $tokenHash, \DateTimeImmutable $now): ?PasswordReset
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->where('r.tokenHash = :hash')
            ->andWhere('r.usedAt IS NULL')
            ->andWhere('r.expiresAt >= :now')
            ->andWhere('u.status = :active')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $now)
            ->setParameter('active', User::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Marks every open reset of the user as used, so older links stop working too.
     */
    public function consumeAllFor(User $user, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.usedAt', ':now')
            ->where('r.user = :user')
            ->andWhere('r.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
