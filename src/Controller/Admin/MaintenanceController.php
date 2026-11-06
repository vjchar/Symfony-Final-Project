<?php

namespace App\Controller\Admin;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Form\MaintenanceRecordType;
use App\Repository\MaintenanceRecordRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-maintenance.php: schedule service events and move them through
 * their statuses, which takes the vehicle offline while work is in progress.
 */
#[IsGranted('ROLE_ADMIN')]
final class MaintenanceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/admin/maintenance', name: 'app_admin_maintenance', methods: ['GET', 'POST'])]
    public function index(Request $request, MaintenanceRecordRepository $records, #[CurrentUser] User $admin): Response
    {
        $record = new MaintenanceRecord();
        $form = $this->createForm(MaintenanceRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $record->setCreatedBy($admin);
            $this->entityManager->persist($record);
            $this->entityManager->flush();
            $this->auditLogger->log('maintenance_created', 'maintenance_record', $record->getId());
            $this->addFlash('success', 'Maintenance record saved.');

            return $this->redirectToRoute('app_admin_maintenance', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/maintenance/index.html.twig', [
            'records' => $records->findAllForAdmin(),
            'statuses' => MaintenanceRecord::STATUSES,
            'form' => $form,
        ]);
    }

    #[Route('/admin/maintenance/{id}/status', name: 'app_admin_maintenance_status', methods: ['POST'])]
    public function status(Request $request, MaintenanceRecord $record): Response
    {
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('maintenance-status-'.$record->getId(), $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $this->redirectToRoute('app_admin_maintenance', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $status = $payload->getString('status');
            $record->changeStatus($status);
            $this->entityManager->flush();
            $this->auditLogger->log('maintenance_status_updated', 'maintenance_record', $record->getId(), ['status' => $status]);
            $this->addFlash('success', 'Maintenance record saved.');
        } catch (\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('app_admin_maintenance', [], Response::HTTP_SEE_OTHER);
    }
}
