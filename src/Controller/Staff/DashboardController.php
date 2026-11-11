<?php

namespace App\Controller\Staff;

use App\Entity\CustomerDocument;
use App\Repository\BookingRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\CustomerDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff landing page: today's work across the front-desk queues.
 */
#[IsGranted('ROLE_STAFF')]
final class DashboardController extends AbstractController
{
    #[Route('/staff', name: 'app_staff', methods: ['GET'])]
    public function index(BookingRepository $bookings, CustomerDocumentRepository $documents, ContactMessageRepository $messages): Response
    {
        $now = new \DateTimeImmutable();
        $desk = $bookings->findForRentalDesk();
        $byStatus = static fn (string $status): array => array_values(array_filter($desk, static fn ($booking): bool => $status === $booking->getStatus()));
        $active = $byStatus('active');

        return $this->render('staff/dashboard/index.html.twig', [
            'preparing' => $bookings->findAwaitingPreparation(),
            'pickups' => $byStatus('ready'),
            'active' => $active,
            'overdue' => array_values(array_filter($active, static fn ($booking): bool => $booking->isOverdue($now))),
            'returned' => $byStatus('returned'),
            'pending_documents' => $documents->findForAdmin(CustomerDocument::STATUS_PENDING)->total,
            'new_messages' => $messages->countNew(),
            'now' => $now,
        ]);
    }
}
