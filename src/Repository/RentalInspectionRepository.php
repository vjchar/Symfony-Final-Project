<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\RentalInspection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RentalInspection>
 */
class RentalInspectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentalInspection::class);
    }

    /**
     * v15 inspections_for_booking(): the booking's inspections keyed by type,
     * in the order they were first recorded (pickup before return).
     *
     * @return array<string, RentalInspection>
     */
    public function findForBooking(Booking $booking): array
    {
        $inspections = [];
        foreach ($this->findBy(['booking' => $booking], ['id' => 'ASC']) as $inspection) {
            $inspections[$inspection->getInspectionType()] = $inspection;
        }

        return $inspections;
    }

    public function findOneForBooking(Booking $booking, string $type): ?RentalInspection
    {
        return $this->findOneBy(['booking' => $booking, 'inspectionType' => $type]);
    }
}
