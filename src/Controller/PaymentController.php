<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Form\PaymentType;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Service\Booking\BookingJourneyResolver;
use App\Service\Payment\PaymentLedger;
use App\Service\Payment\PaymentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 payments.php (submit and track payments for a booking) and invoice.php.
 * Administrators verify the submitted payments in a later step.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class PaymentController extends AbstractController
{
    private const SESSION_EXPIRED = 'Your session expired. Refresh the page and try again.';

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PaymentLedger $ledger,
        private readonly BookingJourneyResolver $journeys,
    ) {
    }

    #[Route('/account/payments', name: 'app_payments', methods: ['GET', 'POST'])]
    public function payments(
        Request $request,
        #[CurrentUser] User $user,
        PaymentManager $manager,
        RentalAdjustmentRequestRepository $adjustments,
        BookingModificationRequestRepository $modifications,
        PaymentRefundRepository $refunds,
    ): Response {
        $eligible = array_values(array_filter(
            $this->bookings->findForUser($user),
            static fn (Booking $booking): bool => !\in_array($booking->getStatus(), ['cancelled', 'rejected', 'no_show'], true),
        ));
        $reference = trim($request->isMethod('POST') ? $request->getPayload()->getString('reference') : $request->query->getString('reference'));
        $booking = '' !== $reference ? $this->bookings->findOneBy(['reference' => $reference]) : ($eligible[0] ?? null);
        if (null !== $booking && !$booking->isOwnedBy($user)) {
            $booking = null;
        }

        $form = $this->createForm(PaymentType::class);
        $form->handleRequest($request);
        $errors = [];
        if ($form->isSubmitted() && null !== $booking) {
            try {
                if (!$form->isValid()) {
                    throw new \DomainException(self::SESSION_EXPIRED);
                }
                $type = (string) $form->get('payment_type')->getData();
                $proof = $form->get('payment_proof')->getData();
                $manager->submit(
                    $booking,
                    $user,
                    $type,
                    (string) $form->get('method')->getData(),
                    (string) $form->get('amount')->getData(),
                    (string) $form->get('transaction_reference')->getData(),
                    $proof instanceof UploadedFile ? $proof : null,
                );

                return $this->afterSubmit($booking, $type);
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('payment/index.html.twig', [
            'eligible' => $eligible,
            'booking' => $booking,
            'summary' => null === $booking ? null : $this->ledger->summary($booking),
            'approved_extension' => null === $booking ? null : $adjustments->findApprovedExtensionFor($booking),
            'approved_modification' => null === $booking ? null : $modifications->findAwaitingPaymentFor($booking),
            'journey' => null === $booking ? null : $this->journeys->resolve($booking),
            'accounts' => $manager->accounts(),
            'customer_refunds' => $refunds->findForUser($user),
            'form' => $form->createView(),
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/bookings/{reference}/invoice', name: 'app_invoice', methods: ['GET'])]
    public function invoice(
        string $reference,
        #[CurrentUser] User $user,
        RentalAdjustmentRequestRepository $adjustments,
        PaymentRefundRepository $refunds,
    ): Response
    {
        $booking = $this->bookings->findOneBy(['reference' => $reference]);
        if (null === $booking || !$booking->isOwnedBy($user)) {
            return $this->render('payment/invoice_not_found.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $extensionTotal = 0;
        foreach ($adjustments->findForBooking($booking) as $adjustment) {
            if ($adjustment->isExtension() && \in_array($adjustment->getStatus(), [RentalAdjustmentRequest::STATUS_ACTIVATED, RentalAdjustmentRequest::STATUS_COMPLETED], true)) {
                $extensionTotal += $adjustment->getPriceDifference();
            }
        }
        $summary = $this->ledger->summary($booking);

        return $this->render('payment/invoice.html.twig', [
            'booking' => $booking,
            'summary' => $summary,
            'extension_total' => $extensionTotal,
            'amount_due' => 'cancelled' === $booking->getStatus() ? 0 : $summary->amountDue(),
            'refunds' => $refunds->summaryForBooking($booking),
            'settlement' => $booking->getSettlement(),
        ]);
    }

    /**
     * v15 payments.php: a change payment goes back to the booking; the
     * deposit or balance moves on to the next unfinished step.
     */
    private function afterSubmit(Booking $booking, string $type): Response
    {
        $bookingPage = $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
        $changeMessages = [
            Payment::TYPE_EXTENSION => 'Extension payment submitted for administrator verification.',
            Payment::TYPE_MODIFICATION => 'Booking modification payment submitted for administrator verification.',
            Payment::TYPE_EXTRA_CHARGE => 'Return-balance payment submitted for administrator verification.',
        ];
        if (isset($changeMessages[$type])) {
            $this->addFlash('success', $changeMessages[$type]);

            return $bookingPage;
        }

        $next = $this->journeys->resolve($booking);
        if ('documents' === $next->stage) {
            $this->addFlash('success', 'Payment submitted. Your next unfinished step is to upload the required documents.');

            return $this->redirectToRoute('app_documents', ['reference' => $booking->getReference(), 'payment_submitted' => 1], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'Payment submitted. The system skipped any completed requirements and moved you to the next unfinished step.');

        return $this->redirect($next->targetUrl, Response::HTTP_SEE_OTHER);
    }
}
