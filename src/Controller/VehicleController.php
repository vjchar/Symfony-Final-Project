<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Form\Model\VehicleFilter;
use App\Form\VehicleFilterType;
use App\Repository\VehicleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public fleet pages: v15 pages/fleet/vehicles.php and vehicle-details.php.
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

    #[Route('/vehicles/{slug}', name: 'app_vehicle_show', methods: ['GET'])]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])] Vehicle $vehicle,
        VehicleRepository $vehicleRepository,
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
            'recently_viewed' => $recentSlugs ? $vehicleRepository->findBy(['slug' => $recentSlugs, 'isActive' => true], ['id' => 'ASC']) : [],
        ]);
    }
}
