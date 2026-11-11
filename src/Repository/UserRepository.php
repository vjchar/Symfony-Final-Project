<?php

namespace App\Repository;

use App\Entity\User;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Rehash on login when the hashing algorithm changes, like v15's password_needs_rehash().
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * v15 admin_users(): newest first, optionally matching name or email.
     *
     * @return Page<User>
     */
    public function search(string $query = '', int $page = 1): Page
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC')->addOrderBy('u.id', 'DESC');

        if ('' !== $query = trim($query)) {
            $qb->andWhere('LOWER(u.name) LIKE :query OR LOWER(u.email) LIKE :query')
                ->setParameter('query', '%'.addcslashes(mb_strtolower($query), '%_').'%');
        }

        return Page::of($qb, $page);
    }

    /**
     * v15 password_reset_record(): an active account with an unexpired reset for this token.
     */
    public function findByUsableResetToken(string $tokenHash, \DateTimeImmutable $now): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.resetTokenHash = :hash')
            ->andWhere('u.resetExpiresAt >= :now')
            ->andWhere('u.status = :active')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $now)
            ->setParameter('active', User::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveAdmins(): int
    {
        return $this->count(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function countActiveCustomers(): int
    {
        return $this->count(['role' => User::ROLE_CUSTOMER, 'status' => User::STATUS_ACTIVE]);
    }

    /**
     * Every account of every role and status, keyed by role (all roles present,
     * zero when there are none).
     *
     * @return array<string, int>
     */
    public function countByRole(): array
    {
        $counts = array_fill_keys(User::ROLES, 0);
        $rows = $this->createQueryBuilder('u')
            ->select('u.role AS role', 'COUNT(u.id) AS total')
            ->groupBy('u.role')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['role']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countActiveStaff(): int
    {
        return $this->count(['role' => User::ROLE_STAFF, 'status' => User::STATUS_ACTIVE]);
    }
}
