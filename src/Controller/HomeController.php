<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    // Catalogue positions v15 index.php features first (Porsche 911, then one SUV, Pickup and Van).
    private const FEATURED_POSITIONS = [0, 12, 14, 15];

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(VehicleRepository $vehicleRepository): Response
    {
        $vehicles = $vehicleRepository->findBy(['isActive' => true], ['id' => 'ASC']);

        $featured = array_values(array_filter(array_map(static fn (int $position): ?Vehicle => $vehicles[$position] ?? null, self::FEATURED_POSITIONS)));
        $featured = array_slice([...$featured, ...array_filter($vehicles, static fn (Vehicle $vehicle): bool => !\in_array($vehicle, $featured, true))], 0, 4);

        $byCategory = [];
        foreach (Vehicle::CATEGORIES as $category) {
            $byCategory[$category] = array_values(array_filter($vehicles, static fn (Vehicle $vehicle): bool => $vehicle->getCategory() === $category));
        }

        return $this->render('home/index.html.twig', [
            'featured_vehicles' => $featured,
            'vehicles_by_category' => $byCategory,
        ]);
    }
}
