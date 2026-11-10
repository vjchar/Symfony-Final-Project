<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Form\Model\VehicleFilter;
use App\Form\VehicleFilterType;
use App\Repository\ReviewRepository;
use App\Repository\VehicleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public fleet pages: v15 pages/fleet/vehicles.php, vehicle-details.php and compare.php.
 */
final class VehicleController extends AbstractController
{
    private const PER_PAGE = 12;
    private const RECENT_SESSION_KEY = 'recent_vehicle_slugs';

    #[Route('/vehicles', name: 'app_vehicle_index', methods: ['GET'])]
    public function index(
        Request $request,
        VehicleRepository $vehicleRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $filter = new VehicleFilter();
        $filterForm = $this->createForm(VehicleFilterType::class, $filter, [
            'action' => $this->generateUrl('app_vehicle_index'),
            'brands' => $vehicleRepository->findActiveBrands(),
        ]);
        $filterForm->handleRequest($request);

        $results = $vehicleRepository->search($filter, max(1, $page), self::PER_PAGE);
        $total = \count($results);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page > $pageCount) {
            $results = $vehicleRepository->search($filter, $page = $pageCount, self::PER_PAGE);
        }

        return $this->render('vehicle/index.html.twig', [
            'vehicles' => iterator_to_array($results),
            'total' => $total,
            'fleet_size' => $vehicleRepository->count(['isActive' => true]),
            // a view, not the form: invalid values just fall back to defaults, so the page stays 200 like v15
            'filter_form' => $filterForm->createView(),
            'filter' => $filter,
            'active_filters' => $filter->toQuery(),
            'categories' => ['All', ...Vehicle::CATEGORIES],
            'page' => max(1, $page),
            'page_count' => $pageCount,
        ]);
    }

    /**
     * v15 compare.php: up to three vehicles side by side, chosen with
     * ?compare[]=slug or ?vehicles=a,b,c; the first three of the fleet otherwise.
     */
    #[Route('/compare', name: 'app_compare', methods: ['GET'])]
    public function compare(Request $request, VehicleRepository $vehicleRepository, ReviewRepository $reviews): Response
    {
        $fleet = $vehicleRepository->findBy(['isActive' => true], ['id' => 'ASC']);
        $requested = $request->query->has('vehicles')
            ? explode(',', $request->query->getString('vehicles'))
            : array_map('strval', array_filter($request->query->all()['compare'] ?? [], 'is_scalar'));
        $bySlug = [];
        foreach ($fleet as $vehicle) {
            $bySlug[$vehicle->getSlug()] = $vehicle;
        }

        $selected = [];
        foreach (\array_slice(array_values(array_unique(array_filter(array_map('trim', $requested)))), 0, 3) as $slug) {
            if (isset($bySlug[$slug])) {
                $selected[] = $bySlug[$slug];
            }
        }
        if ([] === $selected) {
            $selected = \array_slice($fleet, 0, 3);
        }

        $ratings = $reviews->approvedRatings();
        $ratingOf = static fn (Vehicle $vehicle): float => $ratings[$vehicle->getId()]['rating'] ?? 0.0;

        return $this->render('vehicle/compare.html.twig', [
            'fleet' => $fleet,
            'selected' => $selected,
            'ratings' => $ratings,
            'lowest_rate' => [] === $selected ? 0 : min(array_map(static fn (Vehicle $vehicle): int => (int) $vehicle->getPrice(), $selected)),
            'most_seats' => [] === $selected ? 0 : max(array_map(static fn (Vehicle $vehicle): int => (int) $vehicle->getSeats(), $selected)),
            'best_rating' => [] === $selected ? 0.0 : max(array_map($ratingOf, $selected)),
        ]);
    }

    #[Route('/vehicles/{slug}', name: 'app_vehicle_show', methods: ['GET'])]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])] Vehicle $vehicle,
        VehicleRepository $vehicleRepository,
        ReviewRepository $reviews,
    ): Response {
        if (!$vehicle->isActive()) {
            throw $this->createNotFoundException();
        }

        // Session browsing history, newest first, like v15's $_SESSION["recent_vehicle_slugs"].
        $session = $request->getSession();
        $recentSlugs = array_slice(array_values(array_diff((array) $session->get(self::RECENT_SESSION_KEY, []), [$vehicle->getSlug()])), 0, 4);
        $session->set(self::RECENT_SESSION_KEY, array_slice([$vehicle->getSlug(), ...$recentSlugs], 0, 5));

        return $this->render('vehicle/show.html.twig', [
            'vehicle' => $vehicle,
            'related' => $vehicleRepository->findRelated($vehicle),
            'approved_reviews' => $approved = $reviews->findApprovedForVehicle($vehicle),
            'rating_averages' => [] === $approved ? [] : $reviews->approvedCategoryAverages($vehicle),
            'recently_viewed' => $recentSlugs ? $vehicleRepository->findBy(['slug' => $recentSlugs, 'isActive' => true], ['id' => 'ASC']) : [],
        ]);
    }
}
