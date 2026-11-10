<?php

namespace App\Repository;

use App\Entity\BookingDraft;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingDraft>
 */
class BookingDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingDraft::class);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->count(['reference' => $reference]) > 0;
    }

    /**
     * @return list<BookingDraft> active drafts whose 7 days are up
     */
    public function findStaleFor(User $user, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status = :active')
            ->andWhere('d.expiresAt <= :now')
            ->setParameter('user', $user)
            ->setParameter('active', BookingDraft::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * v15 booking_drafts_for_user(): active drafts, most recently saved first.
     *
     * @return list<BookingDraft>
     */
    public function findActiveFor(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('v')
            ->join('d.vehicle', 'v')
            ->where('d.user = :user')
            ->andWhere('d.status = :active')
            ->setParameter('user', $user)
            ->setParameter('active', BookingDraft::STATUS_ACTIVE)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOwned(int $id, User $user): ?BookingDraft
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }
}
