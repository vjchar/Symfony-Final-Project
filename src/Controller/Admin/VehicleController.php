<?php

namespace App\Controller\Admin;

use App\Entity\Vehicle;
use App\Form\VehicleType;
use App\Repository\VehicleRepository;
use App\Service\AuditLogger;
use App\Service\VehicleImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-vehicles.php: paginated vehicle list beside a create/edit form.
 */
#[IsGranted('ROLE_ADMIN')]
final class VehicleController extends AbstractController
{
    private const PER_PAGE = 12;

    #[Route('/admin/vehicles', name: 'app_admin_vehicles', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        VehicleRepository $vehicleRepository,
        EntityManagerInterface $entityManager,
        VehicleImageStorage $vehicleImages,
        AuditLogger $auditLogger,
        #[MapQueryParameter] int $edit = 0,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $editing = $edit > 0 ? $vehicleRepository->find($edit) : null;
        $vehicle = $editing ?? new Vehicle();

        $form = $this->createForm(VehicleType::class, $vehicle, [
            'action' => $this->generateUrl('app_admin_vehicles', array_filter(['edit' => $editing?->getId()])),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $upload = $form->get('vehicleImage')->getData();
            if ($upload instanceof UploadedFile) {
                $vehicle->setImage($vehicleImages->store($upload, (string) $vehicle->getSlug()));
            }

            $entityManager->persist($vehicle);
            $entityManager->flush();
            $auditLogger->log($editing ? 'vehicle_updated' : 'vehicle_created', 'vehicle', $vehicle->getId());
            $this->addFlash('success', $editing ? 'Vehicle updated.' : 'Vehicle created.');

            return $this->redirectToRoute('app_admin_vehicles', ['edit' => $vehicle->getId()], Response::HTTP_SEE_OTHER);
        }

        $total = $vehicleRepository->count();
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pageCount);

        return $this->render('admin/vehicle/index.html.twig', [
            'vehicles' => $vehicleRepository->findBy([], ['id' => 'ASC'], self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
            'editing' => $editing,
            'form' => $form,
        ]);
    }
}
