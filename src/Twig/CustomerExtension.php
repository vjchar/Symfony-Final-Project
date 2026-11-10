<?php

namespace App\Twig;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Repository\FavoriteRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReviewRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Per-request lookups the shared layout and vehicle cards need: the
 * customer's favorites (v15 customer_favorite_vehicle_slugs()), approved
 * ratings and the unread notification count. Each is loaded once per request.
 */
final class CustomerExtension
{
    /** @var list<string>|null */
    private ?array $favoriteSlugs = null;
    /** @var array<int, array{rating: float, count: int}>|null */
    private ?array $ratings = null;

    public function __construct(
        private readonly Security $security,
        private readonly FavoriteRepository $favorites,
        private readonly ReviewRepository $reviews,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /**
     * Administrators have no favorites list, like v15.
     */
    #[AsTwigFunction('is_favorite')]
    public function isFavorite(Vehicle $vehicle): bool
    {
        if (null === $this->favoriteSlugs) {
            $user = $this->security->getUser();
            $this->favoriteSlugs = $user instanceof User && !$user->isAdmin() ? $this->favorites->findSlugsFor($user) : [];
        }

        return \in_array($vehicle->getSlug(), $this->favoriteSlugs, true);
    }

    /**
     * @return array{rating: float, count: int}|null the approved average, or null for a vehicle with no reviews
     */
    #[AsTwigFunction('vehicle_rating')]
    public function vehicleRating(Vehicle $vehicle): ?array
    {
        $this->ratings ??= $this->reviews->approvedRatings();

        return $this->ratings[$vehicle->getId()] ?? null;
    }

    #[AsTwigFunction('unread_notification_count')]
    public function unreadNotificationCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->countUnread($user) : 0;
    }
}
