<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Repository\PaymentRefundRepository;
use App\Repository\PaymentRepository;
use App\Service\Payment\PaymentManager;
use App\Service\Payment\RefundManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-payments.php: verify or fail customer payments, refund part of a
 * verified payment as a correction, and move every refund (cancellation,
 * correction, security deposit, modification) through its workflow.
 */
#[IsGranted('ROLE_ADMIN')]
final class PaymentController extends AbstractController
{
    public const FILTERS = ['all', Payment::STATUS_PENDING, Payment::STATUS_PAID, Payment::STATUS_FAILED];

    private const SESSION_EXPIRED = 'Your session expired. Refresh the page and try again.';

    #[Route('/admin/payments', name: 'app_admin_payments', methods: ['GET'])]
    public function index(
        PaymentRepository $payments,
        PaymentRefundRepository $refunds,
        #[MapQueryParameter] string $status = 'all',
        #[MapQueryParameter(name: 'refund_status')] string $refundStatus = 'all',
        #[MapQueryParameter(name: 'refund_type')] string $refundType = 'all',
        #[MapQueryParameter(name: 'refund_q')] string $refundQuery = '',
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter(name: 'refund_page')] int $refundPage = 1,
    ): Response {
        $filter = \in_array($status, self::FILTERS, true) ? $status : 'all';
        $refundStatus = \in_array($refundStatus, PaymentRefund::STATUSES, true) ? $refundStatus : 'all';
        $refundType = \array_key_exists($refundType, PaymentRefund::TYPE_LABELS) ? $refundType : 'all';
        $refundQuery = trim($refundQuery);

        $list = $payments->findForAdmin($filter, $page);
        $refundList = $refunds->findForAdmin($refundStatus, $refundType, $refundQuery, $refundPage);
        // v15 refundable_balance_for_payment(), for the payments and refunds on the page
        $refundable = [];
        foreach ([...$list->items, ...array_map(static fn (PaymentRefund $refund): Payment => $refund->getPayment(), $refundList->items)] as $payment) {
            $refundable[$payment->getId()] ??= $refunds->refundableBalance($payment);
        }

        return $this->render('admin/payment/index.html.twig', [
            'payments' => $list,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'refunds' => $refundList,
            'refundable' => $refundable,
            'refund_status' => $refundStatus,
            'refund_type' => $refundType,
            'refund_query' => $refundQuery,
            'refund_statuses' => PaymentRefund::STATUSES,
            'refund_type_labels' => PaymentRefund::TYPE_LABELS,
        ]);
    }

    #[Route('/admin/payments/{id}/review', name: 'app_admin_payment_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(int $id, Request $request, #[CurrentUser] User $admin, PaymentRepository $payments, PaymentManager $manager): Response
    {
        $payload = $request->getPayload();
        $payment = $payments->find($id);
        if (!$this->isCsrfTokenValid('review-payment-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);
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

    #[Route('/admin/payments/{id}/refund', name: 'app_admin_payment_refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refund(int $id, Request $request, #[CurrentUser] User $admin, PaymentRepository $payments, RefundManager $refunds): Response
    {
        $payload = $request->getPayload();
        $payment = $payments->find($id);
        if (!$this->isCsrfTokenValid('refund-payment-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);
        } elseif (null === $payment) {
            $this->addFlash('danger', 'Choose a verified payment and valid refund amount.');
        } else {
            try {
                $refunds->createCorrection($payment, $payload->getString('refund_amount'), $payload->getString('refund_reason'), $payload->getString('refund_notes'), $admin);
                $this->addFlash('success', 'Payment refund created and added to Refund Management.');
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirect($this->generateUrl('app_admin_payments').'#refund-history', Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/refunds/{id}/status', name: 'app_admin_refund_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refundStatus(int $id, Request $request, #[CurrentUser] User $admin, PaymentRefundRepository $refunds, RefundManager $manager): Response
    {
        $payload = $request->getPayload();
        $refund = $refunds->find($id);
        if (!$this->isCsrfTokenValid('refund-status-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);
        } elseif (null === $refund) {
            $this->addFlash('danger', 'Refund record not found.');
        } else {
            try {
                $manager->transition($refund, $payload->getString('refund_status'), $admin, $payload->getString('refund_notes'), $payload->getString('refund_reference'));
                $this->addFlash('success', 'Refund status updated.');
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirect($this->generateUrl('app_admin_payments').'#refund-history', Response::HTTP_SEE_OTHER);
    }
}
