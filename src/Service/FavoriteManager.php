<?php

namespace App\Service;

use App\Entity\Favorite;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Repository\FavoriteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * v15 toggle_vehicle_favorite().
 */
final class FavoriteManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FavoriteRepository $favorites,
    ) {
    }

    /**
     * @return bool true when the vehicle is now saved
     */
    public function toggle(User $user, Vehicle $vehicle): bool
    {
        $favorite = $this->favorites->findOneFor($user, $vehicle);
        if (null !== $favorite) {
            $this->entityManager->remove($favorite);
            $this->entityManager->flush();

            return false;
        }

        $this->entityManager->persist(new Favorite($user, $vehicle));
        $this->entityManager->flush();

        return true;
    }
}
