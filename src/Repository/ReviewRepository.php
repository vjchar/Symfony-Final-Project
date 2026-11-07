<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * v15 review_for_booking().
     */
    public function findForBooking(Booking $booking): ?Review
    {
        return $this->findOneBy(['booking' => $booking]);
    }

    /**
     * v15 admin_reviews(): newest first, optionally one status only.
     *
     * @return list<Review>
     */
    public function findForAdmin(string $status = 'pending'): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('u', 'v', 'b', 'p')
            ->join('r.user', 'u')
            ->join('r.vehicle', 'v')
            ->join('r.booking', 'b')
            ->leftJoin('r.photos', 'p')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');
        if ('all' !== $status) {
            $qb->where('r.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * v15 approved_reviews_for_vehicle(): the latest published reviews.
     *
     * @return list<Review>
     */
    public function findApprovedForVehicle(Vehicle $vehicle, int $limit = 8): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->where('r.vehicle = :vehicle AND r.status = :approved')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('approved', Review::STATUS_APPROVED)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * v15 vehicle_rating_averages(): approved averages per rating category.
     *
     * @return array<string, float> Review::CATEGORIES key => average score
     */
    public function approvedCategoryAverages(Vehicle $vehicle): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.cleanliness) AS cleanliness', 'AVG(r.comfort) AS comfort', 'AVG(r.vehicleCondition) AS vehicle_condition', 'AVG(r.pickupExperience) AS pickup_experience', 'AVG(r.customerSupport) AS customer_support')
            ->where('r.vehicle = :vehicle AND r.status = :approved')
            ->setParameter('vehicle', $vehicle)
            ->setParameter('approved', Review::STATUS_APPROVED)
            ->getQuery()
            ->getSingleResult();

        return array_map(static fn (mixed $value): float => (float) $value, $row);
    }

    /**
     * v15 reviewable_bookings_for_user(): the account's completed bookings that
     * have no review yet, most recently updated first.
     *
     * @return list<Booking>
     */
    public function findReviewableBookings(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('b', 'v')
            ->from(Booking::class, 'b')
            ->join('b.vehicle', 'v')
            ->leftJoin(Review::class, 'r', 'WITH', 'r.booking = b')
            ->where('b.user = :user')
            ->andWhere('b.status = :completed')
            ->andWhere('r.id IS NULL')
            ->setParameter('user', $user)
            ->setParameter('completed', 'completed')
            ->orderBy('b.updatedAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved-review averages per vehicle, as v15 vehicle_all() joins them in.
     *
     * @return array<int, array{rating: float, count: int}> vehicle id => average overall score and review count
     */
    public function approvedRatings(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.vehicle) AS vehicle', 'AVG(r.overall) AS rating', 'COUNT(r.id) AS reviews')
            ->where('r.status = :approved')
            ->setParameter('approved', Review::STATUS_APPROVED)
            ->groupBy('r.vehicle')
            ->getQuery()
            ->getArrayResult();

        $ratings = [];
        foreach ($rows as $row) {
            $ratings[(int) $row['vehicle']] = ['rating' => round((float) $row['rating'], 1), 'count' => (int) $row['reviews']];
        }

        return $ratings;
    }
}
