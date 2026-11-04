<?php

namespace App\Repository;

use App\Entity\User;
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
     * @return User[]
     */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC')->addOrderBy('u.id', 'DESC');

        if ('' !== $query = trim($query)) {
            $qb->andWhere('LOWER(u.name) LIKE :query OR LOWER(u.email) LIKE :query')
                ->setParameter('query', '%'.addcslashes(mb_strtolower($query), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countActiveAdmins(): int
    {
        return $this->count(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function countActiveCustomers(): int
    {
        return $this->count(['role' => User::ROLE_CUSTOMER, 'status' => User::STATUS_ACTIVE]);
    }
}
