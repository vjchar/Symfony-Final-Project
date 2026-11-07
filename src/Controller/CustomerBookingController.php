<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Repository\BookingDraftRepository;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingDraftManager;
use App\Service\Booking\BookingJourneyResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 my-bookings.php and saved-bookings.php.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerBookingController extends AbstractController
{
    #[Route('/account/bookings', name: 'app_my_bookings', methods: ['GET'])]
    public function bookings(
        #[CurrentUser] User $user,
        BookingRepository $bookings,
        BookingJourneyResolver $journeys,
        #[MapQueryParameter] string $status = 'all',
    ): Response {
        $filters = ['all', ...Booking::STATUSES];
        $filter = \in_array($status, $filters, true) ? $status : 'all';
        $visible = array_values(array_filter(
            $bookings->findForUser($user),
            static fn (Booking $booking): bool => 'all' === $filter || $booking->getStatus() === $filter,
        ));

        return $this->render('booking/my_bookings.html.twig', [
            'bookings' => array_map(static fn (Booking $booking): array => ['booking' => $booking, 'journey' => $journeys->resolve($booking)], $visible),
            'filter' => $filter,
            'filters' => $filters,
        ]);
    }

    #[Route('/account/saved-bookings', name: 'app_saved_bookings', methods: ['GET'])]
    public function savedBookings(
        #[CurrentUser] User $user,
        BookingDraftRepository $drafts,
        BookingDraftManager $draftManager,
        #[MapQueryParameter] int $draft = 0,
        #[MapQueryParameter] ?string $saved = null,
    ): Response {
        $draftManager->expireStaleFor($user);
        $selected = $draft > 0 ? $drafts->findOwned($draft, $user) : null;

        return $this->render('booking/saved_bookings.html.twig', [
            'drafts' => $drafts->findActiveFor($user),
            'selected' => $selected,
            'preview' => null === $selected ? null : $draftManager->preview($selected),
            'just_saved' => null !== $saved,
        ]);
    }

    #[Route('/account/saved-bookings/{id}/discard', name: 'app_saved_bookings_discard', methods: ['POST'])]
    public function discard(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        BookingDraftRepository $drafts,
        BookingDraftManager $draftManager,
    ): Response {
        $draft = $drafts->findOwned($id, $user);

        if (!$this->isCsrfTokenValid('discard-draft-'.$id, $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $draft) {
            $this->addFlash('danger', 'This saved booking is no longer available to discard.');
        } else {
            try {
                $draftManager->discard($draft);
                $this->addFlash('success', 'Saved booking discarded. No vehicle reservation was affected.');
            } catch (\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_saved_bookings', [], Response::HTTP_SEE_OTHER);
    }
}
