<?php

namespace App\Controller\Staff;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Form\MaintenanceRecordType;
use App\Repository\MaintenanceRecordRepository;
use App\Security\Voter\MaintenanceRecordVoter;
use App\Service\MaintenanceRecordManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff workspace: log maintenance, read every record, and change or
 * delete only the records you created (MaintenanceRecordVoter).
 */
#[IsGranted('ROLE_STAFF')]
#[Route('/staff/maintenance')]
final class MaintenanceController extends AbstractController
{
    public function __construct(
        private readonly MaintenanceRecordManager $manager,
    ) {
    }

    #[Route('', name: 'app_staff_maintenance', methods: ['GET'])]
    public function index(MaintenanceRecordRepository $records): Response
    {
        return $this->render('staff/maintenance/index.html.twig', [
            'records' => $records->findAllForAdmin(),
        ]);
    }

    #[Route('/new', name: 'app_staff_maintenance_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $staff): Response
    {
        $record = new MaintenanceRecord();
        $form = $this->createForm(MaintenanceRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->create($record, $staff);
            $this->addFlash('success', 'Maintenance record saved.');

            return $this->redirectToRoute('app_staff_maintenance_show', ['id' => $record->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('staff/maintenance/form.html.twig', [
            'form' => $form,
            'record' => null,
        ]);
    }

    #[Route('/{id}', name: 'app_staff_maintenance_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(MaintenanceRecordVoter::VIEW, 'record')]
    public function show(MaintenanceRecord $record): Response
    {
        return $this->render('staff/maintenance/show.html.twig', [
            'record' => $record,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_staff_maintenance_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(MaintenanceRecordVoter::EDIT, 'record')]
    public function edit(Request $request, MaintenanceRecord $record): Response
    {
        $form = $this->createForm(MaintenanceRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->update($record);
            $this->addFlash('success', 'Maintenance record updated.');

            return $this->redirectToRoute('app_staff_maintenance_show', ['id' => $record->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('staff/maintenance/form.html.twig', [
            'form' => $form,
            'record' => $record,
        ]);
    }

    /**
     * GET asks "are you sure?"; only the POST from that page deletes.
     */
    #[Route('/{id}/delete', name: 'app_staff_maintenance_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(MaintenanceRecordVoter::DELETE, 'record')]
    public function delete(Request $request, MaintenanceRecord $record): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('staff/maintenance/delete.html.twig', [
                'record' => $record,
            ]);
        }

        if (!$this->isCsrfTokenValid('maintenance-delete-'.$record->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $this->redirectToRoute('app_staff_maintenance_delete', ['id' => $record->getId()], Response::HTTP_SEE_OTHER);
        }

        $this->manager->delete($record);
        $this->addFlash('success', 'Maintenance record deleted.');

        return $this->redirectToRoute('app_staff_maintenance', [], Response::HTTP_SEE_OTHER);
    }
}
