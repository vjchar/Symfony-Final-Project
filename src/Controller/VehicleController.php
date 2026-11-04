<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public fleet pages: v15 pages/fleet/vehicles.php and vehicle-details.php.
 */
final class VehicleController extends AbstractController
{
    private const RECENT_SESSION_KEY = 'recent_vehicle_slugs';

    #[Route('/vehicles', name: 'app_vehicle_index', methods: ['GET'])]
    public function index(VehicleRepository $vehicleRepository): Response
    {
        $vehicles = $vehicleRepository->findBy(['isActive' => true], ['id' => 'ASC']);

        return $this->render('vehicle/index.html.twig', [
            'vehicles' => $vehicles,
            'fleet_size' => \count($vehicles),
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

        $related = $vehicleRepository->findBy(['category' => $vehicle->getCategory(), 'isActive' => true], ['id' => 'ASC']);
        $related = array_slice(array_values(array_filter($related, static fn (Vehicle $other): bool => $other !== $vehicle)), 0, 3);

        // Session browsing history, newest first, like v15's $_SESSION["recent_vehicle_slugs"].
        $session = $request->getSession();
        $recentSlugs = array_slice(array_values(array_diff((array) $session->get(self::RECENT_SESSION_KEY, []), [$vehicle->getSlug()])), 0, 4);
        $session->set(self::RECENT_SESSION_KEY, array_slice([$vehicle->getSlug(), ...$recentSlugs], 0, 5));

        return $this->render('vehicle/show.html.twig', [
            'vehicle' => $vehicle,
            'related' => $related,
            'recently_viewed' => $recentSlugs ? $vehicleRepository->findBy(['slug' => $recentSlugs, 'isActive' => true], ['id' => 'ASC']) : [],
        ]);
    }
}
