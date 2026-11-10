<?php

namespace App\Repository;

use App\Entity\Favorite;
use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    public function findOneFor(User $user, Vehicle $vehicle): ?Favorite
    {
        return $this->findOneBy(['user' => $user, 'vehicle' => $vehicle]);
    }

    /**
     * v15 favorite_slugs().
     *
     * @return list<string>
     */
    public function findSlugsFor(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->select('v.slug')
            ->join('f.vehicle', 'v')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * The saved vehicles that are still in the active fleet, in fleet order like v15 favorites.php.
     *
     * @return list<Vehicle>
     */
    public function findActiveVehiclesFor(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('v')
            ->from(Vehicle::class, 'v')
            ->join(Favorite::class, 'f', 'WITH', 'f.vehicle = v AND f.user = :user')
            ->where('v.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
