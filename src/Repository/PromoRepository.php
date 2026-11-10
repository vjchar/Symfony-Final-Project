<?php

namespace App\Repository;

use App\Entity\Promo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Promo>
 */
class PromoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promo::class);
    }

    /**
     * v15 promo_find(): an active code inside its dates that still has uses left.
     */
    public function findRedeemable(string $code, \DateTimeImmutable $now): ?Promo
    {
        return $this->createQueryBuilder('p')
            ->where('UPPER(p.code) = UPPER(:code)')
            ->andWhere('p.isActive = true')
            ->andWhere('p.startsAt IS NULL OR p.startsAt <= :now')
            ->andWhere('p.endsAt IS NULL OR p.endsAt >= :now')
            ->andWhere('p.maxUses IS NULL OR p.usedCount < p.maxUses')
            ->setParameter('code', trim($code))
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Counts one redemption in the database, so concurrent bookings can't lose an update.
     */
    public function recordUse(Promo $promo): void
    {
        $this->createQueryBuilder('p')
            ->update()
            ->set('p.usedCount', 'p.usedCount + 1')
            ->set('p.updatedAt', ':now')
            ->where('p = :promo')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('promo', $promo)
            ->getQuery()
            ->execute();
    }
}
