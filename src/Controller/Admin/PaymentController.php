<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Service\Payment\PaymentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-payments.php: verify or fail customer payments. (Its refund
 * management panel comes with the refunds step.)
 */
#[IsGranted('ROLE_ADMIN')]
final class PaymentController extends AbstractController
{
    public const FILTERS = ['all', Payment::STATUS_PENDING, Payment::STATUS_PAID, Payment::STATUS_FAILED];

    #[Route('/admin/payments', name: 'app_admin_payments', methods: ['GET'])]
    public function index(PaymentRepository $payments, #[MapQueryParameter] string $status = 'all'): Response
    {
        $filter = \in_array($status, self::FILTERS, true) ? $status : 'all';

        return $this->render('admin/payment/index.html.twig', [
            'payments' => $payments->findForAdmin($filter),
            'filter' => $filter,
            'filters' => self::FILTERS,
        ]);
    }

    #[Route('/admin/payments/{id}/review', name: 'app_admin_payment_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(int $id, Request $request, #[CurrentUser] User $admin, PaymentRepository $payments, PaymentManager $manager): Response
    {
        $payload = $request->getPayload();
        $payment = $payments->find($id);
        if (!$this->isCsrfTokenValid('review-payment-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $payment) {
            $this->addFlash('danger', 'Payment not found.');
        } else {
            try {
                $manager->review($payment, $payload->getString('status'), $payload->getString('notes'), $admin);
                $this->addFlash('success', 'Payment status updated.');
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_payments', ['status' => $request->query->getString('status', 'all')], Response::HTTP_SEE_OTHER);
    }
}
