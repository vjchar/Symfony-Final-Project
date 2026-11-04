<?php

namespace App\Controller\Admin;

use App\Repository\AddonRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\VehicleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(
        VehicleRepository $vehicleRepository,
        AddonRepository $addonRepository,
        ContactMessageRepository $contactMessageRepository,
    ): Response {
        $hour = (int) (new \DateTimeImmutable())->format('G');
        $categoryCounts = $vehicleRepository->countActiveByCategory();

        return $this->render('admin/dashboard/index.html.twig', [
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            },
            'counts' => [
                'vehicles' => array_sum($categoryCounts),
                'available' => $vehicleRepository->countActiveByStatus('available'),
                'maintenance' => $vehicleRepository->countActiveByStatus('maintenance'),
                'addons' => $addonRepository->count(['isActive' => true]),
                'new_messages' => $contactMessageRepository->countNew(),
            ],
            'category_counts' => $categoryCounts,
            'category_maximum' => max(1, ...array_values($categoryCounts)),
        ]);
    }
}
