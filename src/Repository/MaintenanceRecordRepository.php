<?php

namespace App\Repository;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaintenanceRecord>
 */
class MaintenanceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaintenanceRecord::class);
    }

    /**
     * v15 maintenance_records(): newest first, with the vehicle and creator loaded.
     *
     * @return list<MaintenanceRecord>
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('v', 'u')
            ->join('m.vehicle', 'v')
            ->join('m.createdBy', 'u')
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The record list's search and filter, newest first. $query matches the
     * vehicle name, service title, description or creator name; $status
     * narrows to one status and is ignored when it isn't a known one.
     *
     * @return list<MaintenanceRecord>
     */
    public function search(string $query = '', string $status = ''): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('v', 'u')
            ->join('m.vehicle', 'v')
            ->join('m.createdBy', 'u')
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if ('' !== $query = trim($query)) {
            $qb->andWhere('LOWER(v.name) LIKE :query OR LOWER(m.title) LIKE :query OR LOWER(m.description) LIKE :query OR LOWER(u.name) LIKE :query')
                ->setParameter('query', '%'.addcslashes(mb_strtolower($query), '%_\\').'%');
        }

        if (\in_array($status, MaintenanceRecord::STATUSES, true)) {
            $qb->andWhere('m.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * v15 booking_modification_maintenance_conflict(): scheduled or ongoing
     * work overlaps the period. Work with no end date blocks everything after it.
     */
    public function hasConflict(Vehicle $vehicle, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.vehicle = :vehicle')
            ->andWhere('m.status IN (:statuses)')
            ->andWhere('m.startsAt < :to')
            ->andWhere('m.endsAt IS NULL OR m.endsAt > :from')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('statuses', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS])
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
