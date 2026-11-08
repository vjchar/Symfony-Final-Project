<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\BookingDraft;
use App\Entity\User;
use App\Form\BookingType;
use App\Form\Model\BookingRequest;
use App\Repository\AddonRepository;
use App\Repository\BookingCancellationRequestRepository;
use App\Repository\BookingDraftRepository;
use App\Repository\BookingModificationRequestRepository;
use App\Repository\BookingRepository;
use App\Repository\PaymentRefundRepository;
use App\Repository\RentalAdjustmentRequestRepository;
use App\Repository\ReviewRepository;
use App\Repository\VehicleRepository;
use App\Service\Booking\BookingCalculator;
use App\Service\Booking\BookingDraftManager;
use App\Service\Booking\BookingJourneyResolver;
use App\Service\Booking\BookingManager;
use App\Service\Booking\BookingQuote;
use App\Service\Booking\DraftPreview;
use App\Service\Document\DocumentManager;
use App\Service\Payment\PaymentLedger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 booking.php (build, estimate, save for later, book), booking-estimate.php,
 * booking-confirmation.php, booking-view.php and manage-booking.php.
 */
final class BookingController extends AbstractController
{
    private const SESSION_EXPIRED = 'Your session expired. Refresh the page and try again.';

    public function __construct(
        private readonly BookingCalculator $calculator,
        private readonly BookingRepository $bookings,
        private readonly BookingJourneyResolver $journeys,
    ) {
    }

    #[Route('/booking', name: 'app_booking', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function book(
        Request $request,
        #[CurrentUser] User $user,
        VehicleRepository $vehicles,
        AddonRepository $addons,
        BookingDraftRepository $draftRepository,
        BookingDraftManager $draftManager,
        BookingManager $bookingManager,
        #[MapQueryParameter] int $draft = 0,
        #[MapQueryParameter(name: 'source_draft')] int $sourceDraft = 0,
    ): Response {
        $draftManager->expireStaleFor($user);
        $activeDraft = null;
        $preview = null;
        $bookingRequest = null;

        if ($draft > 0) {
            $activeDraft = $draftRepository->findOwned($draft, $user);
            if (null === $activeDraft || !$activeDraft->isActive()) {
                $this->addFlash('warning', 'That saved booking is unavailable or has expired.');

                return $this->redirectToRoute('app_saved_bookings');
            }
            [$bookingRequest, $preview] = $this->fromDraft($activeDraft, $draftManager);
        } elseif ($sourceDraft > 0) {
            $source = $draftRepository->findOwned($sourceDraft, $user);
            if (null === $source) {
                $this->addFlash('warning', 'That saved booking could not be reused.');

                return $this->redirectToRoute('app_saved_bookings');
            }
            [$bookingRequest, $preview] = $this->fromDraft($source, $draftManager);
        }

        $bookingRequest ??= $this->fromQuery($request);
        $form = $this->createForm(BookingType::class, $bookingRequest);
        $form->handleRequest($request);

        $quote = $preview?->quote;
        $errors = [];
        [$message, $messageClass] = null === $activeDraft
            ? ['Enter complete trip details. Your estimate updates automatically.', '']
            : ($preview->available
                ? ['Saved booking loaded. The vehicle is currently available; pricing has been recalculated.', 'is-success']
                : ['' !== $preview->error ? $preview->error : 'Review this saved booking before continuing.', 'is-error']);

        if ($form->isSubmitted()) {
            $action = $request->getPayload()->getString('form_action', 'create_booking');

            try {
                if (!$form->isValid()) {
                    throw new \DomainException(self::SESSION_EXPIRED);
                }

                if ('select_vehicle' === $action) {
                    $vehicle = $vehicles->findOneBy(['slug' => $bookingRequest->vehicle, 'isActive' => true]);
                    $message = $vehicle?->getName().' is now selected. Enter your schedule to check availability and calculate the total.';
                    $messageClass = 'is-success';
                } else {
                    $quote = $this->availableQuote($bookingRequest);

                    if ('preview' === $action) {
                        $message = 'Available for the selected schedule. Your estimate has been updated.';
                        $messageClass = 'is-success';
                    } elseif ('save_draft' === $action) {
                        $saved = $draftManager->save($user, $quote, $activeDraft);
                        $this->addFlash('success', 'Booking saved for later. The vehicle is not reserved until you proceed with the booking.');

                        return $this->redirectToRoute('app_saved_bookings', ['draft' => $saved->getId(), 'saved' => 1], Response::HTTP_SEE_OTHER);
                    } else {
                        if (!$bookingRequest->terms) {
                            throw new \InvalidArgumentException('Review and accept the rental terms before continuing.');
                        }

                        $booking = null === $activeDraft
                            ? $bookingManager->create($user, $quote)
                            : $bookingManager->convertDraft($user, $draftManager->save($user, $quote, $activeDraft));
                        $this->addFlash('success', 'Booking created. Continue with the next unfinished step shown below.');

                        return $this->redirectToRoute('app_booking_confirmation', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
                    }
                }
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
                $message = $error->getMessage();
                $messageClass = 'is-error';
            }
        }

        $activeVehicles = $vehicles->findBy(['isActive' => true], ['id' => 'ASC']);
        $selectedVehicle = $quote?->vehicle
            ?? $vehicles->findOneBy(['slug' => $bookingRequest->vehicle, 'isActive' => true])
            ?? $activeVehicles[0] ?? null;

        return $this->render('booking/book.html.twig', [
            'form' => $form->createView(),
            'booking_request' => $bookingRequest,
            'vehicles' => $activeVehicles,
            'addons' => $addons->findBy(['isActive' => true], ['id' => 'ASC']),
            'locations' => BookingCalculator::LOCATIONS,
            'selected_vehicle' => $selectedVehicle,
            'quote' => $quote,
            'errors' => $errors,
            'message' => $message,
            'message_class' => $messageClass,
            'active_draft' => $activeDraft,
            'from_source_draft' => $sourceDraft > 0 && null === $activeDraft,
            'preview' => $preview,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * v15 booking-estimate.php: the live price used by booking-vehicle-preview.js.
     */
    #[Route('/booking/estimate', name: 'app_booking_estimate', methods: ['POST'])]
    public function estimate(
        Request $request,
        #[Target('booking_estimate')] RateLimiterFactoryInterface $estimateLimiter,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isGranted('ROLE_CUSTOMER')) {
            return $this->estimateError('Sign in with a customer account to calculate a booking estimate.', Response::HTTP_UNAUTHORIZED);
        }
        if (!$estimateLimiter->create('user-'.$user->getId())->consume()->isAccepted()) {
            return $this->estimateError('Too many price checks in a short time. Wait a moment and try again.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $bookingRequest = new BookingRequest();
        $form = $this->createForm(BookingType::class, $bookingRequest);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            // v15 answers an expired CSRF token with 419
            return $this->estimateError(self::SESSION_EXPIRED, 419);
        }

        try {
            $quote = $this->availableQuote($bookingRequest);
        } catch (\InvalidArgumentException|\DomainException $error) {
            return $this->estimateError($error->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $money = static fn (int $amount): string => '₱'.number_format($amount);

        return $this->noStore(new JsonResponse([
            'ok' => true,
            'message' => 'Available for the selected schedule. The estimate was verified on the server.',
            'estimate' => [
                'days' => $quote->days,
                'subtotal' => $quote->subtotal,
                'addons_total' => $quote->addonsTotal,
                'delivery_fee' => $quote->deliveryFee,
                'discount' => $quote->discount,
                'deposit' => $quote->deposit(),
                'total' => $quote->total(),
                'formatted' => [
                    'subtotal' => $money($quote->subtotal),
                    'addons_total' => $money($quote->addonsTotal),
                    'delivery_fee' => $money($quote->deliveryFee),
                    'discount' => $quote->discount > 0 ? '−'.$money($quote->discount) : $money(0),
                    'deposit' => $money($quote->deposit()),
                    'total' => $money($quote->total()),
                ],
            ],
        ]));
    }

    #[Route('/booking/{reference}/confirmation', name: 'app_booking_confirmation', methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function confirmation(string $reference, #[CurrentUser] User $user): Response
    {
        $booking = $this->bookings->findOneBy(['reference' => $reference]);
        if (null === $booking || !$booking->isOwnedBy($user)) {
            $this->addFlash('danger', 'That booking could not be found.');

            return $this->redirectToRoute('app_my_bookings');
        }

        return $this->render('booking/confirmation.html.twig', [
            'booking' => $booking,
            'journey' => $this->journeys->resolve($booking),
        ]);
    }

    #[Route('/bookings/{reference}', name: 'app_booking_show', methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function show(
        string $reference,
        #[CurrentUser] User $user,
        BookingModificationRequestRepository $modifications,
        BookingCancellationRequestRepository $cancellations,
        RentalAdjustmentRequestRepository $adjustments,
        PaymentLedger $ledger,
        DocumentManager $documents,
        PaymentRefundRepository $refunds,
        ReviewRepository $reviews,
    ): Response {
        $booking = $this->bookings->findOneBy(['reference' => $reference]);
        if (null === $booking || !$booking->isOwnedBy($user)) {
            return $this->render('booking/not_found.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('booking/show.html.twig', [
            'settlement' => $booking->getSettlement(),
            'refunds' => $refunds->findForBooking($booking),
            'review' => $reviews->findForBooking($booking),
            'booking' => $booking,
            'journey' => $this->journeys->resolve($booking),
            'payment_summary' => $ledger->summary($booking),
            'documents' => $documents->checklist($user),
            'unresolved_modification' => $modifications->findUnresolvedFor($booking),
            'modifications' => $modifications->findForBooking($booking),
            'cancellation' => $cancellations->findLatestFor($booking),
            'unresolved_adjustment' => $adjustments->findUnresolvedFor($booking),
        ]);
    }

    #[Route('/manage-booking', name: 'app_manage_booking', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function manage(Request $request, #[CurrentUser] User $user, #[MapQueryParameter] string $reference = ''): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $payload = $request->getPayload();
            $reference = strtoupper(trim($payload->getString('reference')));
            $booking = $this->bookings->findOneBy(['reference' => $reference]);

            if (!$this->isCsrfTokenValid('manage-booking', $payload->getString('_token'))) {
                $error = self::SESSION_EXPIRED;
            } elseif (null === $booking || !$booking->isOwnedBy($user)) {
                $error = 'No accessible booking matches that reference.';
            } else {
                return $this->redirectToRoute('app_booking_show', ['reference' => $booking->getReference()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('booking/manage.html.twig', [
            'reference' => $reference,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    private function availableQuote(BookingRequest $bookingRequest): BookingQuote
    {
        $quote = $this->calculator->quote($bookingRequest);
        if (!$this->bookings->isVehicleAvailable($quote->vehicle, $quote->pickupAt, $quote->returnAt)) {
            throw new \DomainException('This vehicle is already reserved during part of the selected schedule.');
        }

        return $quote;
    }

    /**
     * @return array{BookingRequest, DraftPreview}
     */
    private function fromDraft(BookingDraft $draft, BookingDraftManager $draftManager): array
    {
        $preview = $draftManager->preview($draft);
        // a promotion that lapsed is left out of the form, as in the preview
        $bookingRequest = $draft->toRequest('' !== $preview->promoWarning ? $preview->effectivePromoCode : null);

        return [$bookingRequest, $preview];
    }

    /**
     * Links such as "Book This Vehicle" pass the vehicle and dates in the query string.
     */
    private function fromQuery(Request $request): BookingRequest
    {
        $bookingRequest = new BookingRequest();
        $bookingRequest->vehicle = $request->query->getString('vehicle', 'porsche-911');
        foreach (['pickup' => 'pickupDate', 'return' => 'returnDate'] as $parameter => $property) {
            $date = $request->query->getString($parameter);
            if (false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
                $bookingRequest->$property = $date;
            }
        }

        return $bookingRequest;
    }

    private function estimateError(string $message, int $status): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => false, 'message' => $message], $status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
