<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Service\Booking\RentalAdjustmentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-rentals.php, its rental adjustment queue. (Checkout, check-in and
 * settlement arrive with the rental desk step.)
 */
#[IsGranted('ROLE_ADMIN')]
final class RentalController extends AbstractController
{
    #[Route('/admin/rentals', name: 'app_admin_rentals', methods: ['GET'])]
    public function index(RentalAdjustmentRequestRepository $adjustments): Response
    {
        return $this->render('admin/rental/index.html.twig', [
            'adjustments' => $adjustments->findOpen(),
        ]);
    }

    #[Route('/admin/rentals/adjustments/{id}/review', name: 'app_admin_adjustment_review', methods: ['POST'])]
    public function review(
        int $id,
        Request $request,
        #[CurrentUser] User $admin,
        RentalAdjustmentRequestRepository $adjustments,
        RentalAdjustmentManager $manager,
    ): Response {
        $payload = $request->getPayload();
        $back = $this->redirectToRoute('app_admin_rentals', status: Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('review-adjustment-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $back;
        }

        try {
            $adjustment = $adjustments->find($id) ?? throw new \DomainException('This request is no longer eligible for review.');
            $manager->review($adjustment, $payload->getString('decision'), $admin, $payload->getString('admin_note'));
            $this->addFlash('success', 'Rental adjustment request reviewed.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $back;
    }
}
