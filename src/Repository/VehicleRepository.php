<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Form\Model\VehicleFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * One page of the public fleet search (v15 vehicles.php filters, sort and pagination).
     *
     * @return Paginator<Vehicle>
     */
    public function search(VehicleFilter $filter, int $page, int $perPage): Paginator
    {
        $qb = $this->activeVehicles();

        if ('' !== $search = trim((string) $filter->search)) {
            $qb->andWhere("CONCAT(v.brand, ' ', v.name) LIKE :search")
                ->setParameter('search', '%'.addcslashes($search, '%_').'%');
        }

        foreach (['category' => 'category', 'brand' => 'brand', 'fuel' => 'fuel', 'transmission' => 'transmission', 'status' => 'availabilityStatus'] as $field => $column) {
            if ('All' !== $filter->$field) {
                $qb->andWhere(sprintf('v.%s = :%s', $column, $field))->setParameter($field, $filter->$field);
            }
        }

        if ($filter->seats > 0) {
            $qb->andWhere('v.seats >= :seats')->setParameter('seats', $filter->seats);
        }

        if ($filter->maxPrice > 0) {
            $qb->andWhere('v.price <= :maxPrice')->setParameter('maxPrice', $filter->maxPrice);
        }

        // free for the whole period: bookable, no overlapping booking or maintenance (as BookingRepository::isVehicleAvailable)
        if (null !== $period = $filter->period()) {
            $qb->andWhere('v.availabilityStatus NOT IN (:offline)')
                ->andWhere(sprintf('NOT EXISTS (SELECT b.id FROM %s b WHERE b.vehicle = v AND b.status IN (:blocking) AND b.pickupAt < :periodEnd AND b.returnAt > :periodStart)', Booking::class))
                ->andWhere(sprintf('NOT EXISTS (SELECT m.id FROM %s m WHERE m.vehicle = v AND m.status IN (:servicing) AND m.startsAt < :periodEnd AND (m.endsAt IS NULL OR m.endsAt > :periodStart))', MaintenanceRecord::class))
                ->setParameter('offline', ['maintenance', 'unavailable'])
                ->setParameter('blocking', Booking::BLOCKING_STATUSES)
                ->setParameter('servicing', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS])
                ->setParameter('periodStart', $period[0])
                ->setParameter('periodEnd', $period[1]);
        }

        match ($filter->sort) {
            'price-asc' => $qb->orderBy('v.price', 'ASC'),
            'price-desc' => $qb->orderBy('v.price', 'DESC'),
            'newest' => $qb->orderBy('v.createdAt', 'DESC'),
            'name-asc' => $qb->orderBy('v.name', 'ASC'),
            default => null,
        };
        $qb->addOrderBy('v.id', 'ASC');

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * @return list<string> brands of bookable vehicles, A-Z
     */
    public function findActiveBrands(): array
    {
        $brands = $this->activeVehicles()
            ->select('DISTINCT v.brand')
            ->getQuery()
            ->getSingleColumnResult();

        natcasesort($brands);

        return array_values($brands);
    }

    /**
     * @return Vehicle[] up to $limit other active vehicles in the same category
     */
    public function findRelated(Vehicle $vehicle, int $limit = 3): array
    {
        return $this->activeVehicles()
            ->andWhere('v.category = :category')
            ->andWhere('v != :vehicle')
            ->setParameter('category', $vehicle->getCategory())
            ->setParameter('vehicle', $vehicle)
            ->orderBy('v.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int> active vehicle count keyed by category, in v15 category order
     */
    public function countActiveByCategory(): array
    {
        $rows = $this->activeVehicles()
            ->select('v.category AS category, COUNT(v.id) AS total')
            ->groupBy('v.category')
            ->getQuery()
            ->getArrayResult();
        $totals = array_column($rows, 'total', 'category');

        $counts = [];
        foreach (Vehicle::CATEGORIES as $category) {
            $counts[$category] = (int) ($totals[$category] ?? 0);
        }

        return $counts;
    }

    public function countActiveByStatus(string $status): int
    {
        return $this->count(['isActive' => true, 'availabilityStatus' => $status]);
    }

    private function activeVehicles(): QueryBuilder
    {
        return $this->createQueryBuilder('v')->andWhere('v.isActive = true');
    }
}
