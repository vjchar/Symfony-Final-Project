<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Form\BookingCancellationType;
use App\Form\BookingType;
use App\Form\RentalAdjustmentType;
use App\Repository\AddonRepository;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\VehicleRepository;
use App\Service\Booking\BookingCalculator;
use App\Service\Booking\BookingCancellationManager;
use App\Service\Booking\BookingModificationManager;
use App\Service\Booking\ModificationPayload;
use App\Service\Booking\RentalAdjustmentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 booking-modification.php, booking-cancellation.php and
 * rental-adjustment.php: a customer's tracked requests to change a booking.
 * Each is reviewed by an administrator (Admin\BookingController and
 * Admin\RentalController) before the booking itself changes.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class BookingChangeController extends AbstractController
{
    private const SESSION_EXPIRED = 'Your session expired. Refresh the page and try again.';

    public function __construct(
        private readonly BookingRepository $bookings,
    ) {
    }

    #[Route('/bookings/{reference}/modify', name: 'app_booking_modification', methods: ['GET', 'POST'])]
    public function modify(
        string $reference,
        Request $request,
        #[CurrentUser] User $user,
        BookingModificationManager $manager,
        BookingModificationRequestRepository $modifications,
        VehicleRepository $vehicles,
        AddonRepository $addons,
    ): Response {
        $booking = $this->ownedBooking($reference, $user);
        if (null === $booking) {
            return $this->notFound('The reservation is not available to this account.');
        }

        $bookingRequest = ModificationPayload::requestFromBooking($booking);
        $form = $this->createForm(BookingType::class, $bookingRequest);
        $form->handleRequest($request);

        $errors = [];
        $preview = null;
        $reason = '';
        if ($form->isSubmitted()) {
            $payload = $request->getPayload();
            $reason = $payload->getString('reason');
            try {
                if (!$form->isValid()) {
                    throw new \DomainException(self::SESSION_EXPIRED);
                }
                $preview = $manager->preview($booking, $bookingRequest);
                if ('submit_request' === $payload->getString('action')) {
                    $manager->request($booking, $user, $bookingRequest, $reason);
                    $this->addFlash('success', 'Booking modification request submitted for administrator review.');

                    return $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
                }
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('booking/modification.html.twig', [
            'booking' => $booking,
            'form' => $form->createView(),
            'booking_request' => $bookingRequest,
            'reason' => $reason,
            'preview' => $preview,
            'errors' => $errors,
            'existing' => $modifications->findUnresolvedFor($booking),
            'history' => $modifications->findForBooking($booking),
            'vehicles' => $vehicles->findBy(['isActive' => true], ['id' => 'ASC']),
            'addons' => $addons->findBy(['isActive' => true], ['id' => 'ASC']),
            'locations' => BookingCalculator::LOCATIONS,
            'pickup_methods' => BookingCalculator::PICKUP_METHODS,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/bookings/{reference}/modify/{id}/withdraw', name: 'app_booking_modification_withdraw', methods: ['POST'])]
    public function withdrawModification(
        string $reference,
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        BookingModificationManager $manager,
        BookingModificationRequestRepository $modifications,
    ): Response {
        $modification = $modifications->find($id);
        $this->withdraw(
            $request,
            'withdraw-modification-'.$id,
            $modification?->getBooking()->getReference() === $reference ? $modification : null,
            static fn (BookingModificationRequest $modification) => $manager->withdraw($modification, $user),
            'Modification request cancelled.',
            'That modification request can no longer be cancelled.',
        );

        return $this->redirectToRoute('app_booking_modification', ['reference' => $reference], Response::HTTP_SEE_OTHER);
    }

    #[Route('/bookings/{reference}/cancel', name: 'app_booking_cancellation', methods: ['GET', 'POST'])]
    public function cancel(
        string $reference,
        Request $request,
        #[CurrentUser] User $user,
        BookingCancellationManager $manager,
        BookingCancellationRequestRepository $cancellations,
    ): Response {
        $booking = $this->ownedBooking($reference, $user);
        if (null === $booking) {
            return $this->notFound();
        }

        $form = $this->createForm(BookingCancellationType::class);
        $form->handleRequest($request);

        $errors = [];
        if ($form->isSubmitted()) {
            try {
                if (!$form->isValid()) {
                    throw new \DomainException(self::SESSION_EXPIRED);
                }
                $manager->request($booking, $user, (string) $form->get('reason')->getData());
                $this->addFlash('success', 'Cancellation request submitted for administrator review.');

                return $this->redirectToRoute('app_booking_cancellation', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('booking/cancellation.html.twig', [
            'booking' => $booking,
            'form' => $form->createView(),
            'errors' => $errors,
            'cancellation' => $cancellations->findLatestFor($booking),
            'estimate' => $manager->estimate($booking),
            'cutoff_hours' => BookingCancellationManager::REFUND_CUTOFF_HOURS,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/bookings/{reference}/adjust', name: 'app_rental_adjustment', methods: ['GET', 'POST'])]
    public function adjust(
        string $reference,
        Request $request,
        #[CurrentUser] User $user,
        RentalAdjustmentManager $manager,
        RentalAdjustmentRequestRepository $adjustments,
    ): Response {
        $booking = $this->ownedBooking($reference, $user);
        if (null === $booking) {
            return $this->notFound('This rental is not available to your account.', 'Rental not found');
        }
        if ('active' !== $booking->getStatus()) {
            $this->addFlash('warning', 'Rental adjustments are available only while the rental is active.');

            return $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()]);
        }

        $form = $this->createForm(RentalAdjustmentType::class);
        $form->handleRequest($request);

        $errors = [];
        $preview = null;
        if ($form->isSubmitted()) {
            $action = $request->getPayload()->getString('action');
            [$date, $time, $reason] = [$form->get('requested_date')->getData(), $form->get('requested_time')->getData(), $form->get('reason')->getData()];
            try {
                if (!$form->isValid()) {
                    // errors on the form itself come from the CSRF token; the rest are field constraints
                    $fieldError = $form->getErrors(true)->current();
                    throw new \InvalidArgumentException(0 === \count($form->getErrors()) && false !== $fieldError ? $fieldError->getMessage() : self::SESSION_EXPIRED);
                }
                if ('preview_extension' === $action) {
                    $preview = $manager->previewExtension($booking, $user, $date, $time);
                } elseif ('submit_extension' === $action) {
                    $manager->request($booking, $user, RentalAdjustmentRequest::TYPE_EXTENSION, $date, $time, $reason);
                    $this->addFlash('success', 'Extension request submitted for administrator review.');

                    return $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
                } elseif ('submit_early_return' === $action) {
                    $manager->request($booking, $user, RentalAdjustmentRequest::TYPE_EARLY_RETURN, $date, $time, $reason);
                    $this->addFlash('success', 'Early return request submitted. The actual return will still be recorded at check-in.');

                    return $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
                } else {
                    throw new \InvalidArgumentException('Choose a valid rental adjustment action.');
                }
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('booking/rental_adjustment.html.twig', [
            'booking' => $booking,
            // each of the page's two forms renders its own token
            'early_form' => $form->createView(),
            'extension_form' => $this->createForm(RentalAdjustmentType::class)->createView(),
            'errors' => $errors,
            'preview' => $preview,
            'adjustments' => $adjustments->findForBooking($booking),
            'unresolved' => $adjustments->findUnresolvedFor($booking),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/bookings/{reference}/adjust/{id}/withdraw', name: 'app_rental_adjustment_withdraw', methods: ['POST'])]
    public function withdrawAdjustment(
        string $reference,
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        RentalAdjustmentManager $manager,
        RentalAdjustmentRequestRepository $adjustments,
    ): Response {
        $adjustment = $adjustments->find($id);
        $this->withdraw(
            $request,
            'withdraw-adjustment-'.$id,
            $adjustment?->getBooking()->getReference() === $reference ? $adjustment : null,
            static fn (RentalAdjustmentRequest $adjustment) => $manager->withdraw($adjustment, $user),
            'Pending rental adjustment cancelled.',
            'That rental adjustment cannot be cancelled.',
        );

        return $this->redirectToRoute('app_rental_adjustment', ['reference' => $reference], Response::HTTP_SEE_OTHER);
    }

    /**
     * @template T of object
     *
     * @param T|null            $item
     * @param callable(T): void $withdraw throws \DomainException when it can't be withdrawn
     */
    private function withdraw(Request $request, string $tokenId, ?object $item, callable $withdraw, string $success, string $refused): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', self::SESSION_EXPIRED);

            return;
        }

        try {
            if (null === $item) {
                throw new \DomainException($refused);
            }
            $withdraw($item);
            $this->addFlash('success', $success);
        } catch (\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }
    }

    private function ownedBooking(string $reference, User $user): ?Booking
    {
        $booking = $this->bookings->findOneBy(['reference' => $reference]);

        return null !== $booking && $booking->isOwnedBy($user) ? $booking : null;
    }

    private function notFound(string $message = 'The reservation does not exist or is not available to this account.', string $heading = 'Booking not found'): Response
    {
        return $this->render('booking/not_found.html.twig', [
            'heading' => $heading,
            'message' => $message,
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
