<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Form\ProfileType;
use App\Repository\BookingDraftRepository;
use App\Repository\BookingRepository;
use App\Service\AuditLogger;
use App\Service\Booking\BookingDraftManager;
use App\Service\Booking\BookingJourneyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 account.php (the customer dashboard) and profile.php. The dashboard is
 * customers only, like v15 require_customer(); every signed-in account,
 * administrators and staff included, can edit its own profile and password.
 */
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function index(
        #[CurrentUser] User $user,
        BookingRepository $bookingRepository,
        BookingDraftRepository $draftRepository,
        BookingDraftManager $draftManager,
        BookingJourneyResolver $journeys,
    ): Response {
        $draftManager->expireStaleFor($user);
        $bookings = $bookingRepository->findForUser($user);
        $statuses = array_count_values(array_map(static fn (Booking $booking): string => $booking->getStatus(), $bookings));

        return $this->render('account/index.html.twig', [
            'priority' => $journeys->priority($bookings),
            'latest_bookings' => array_map(
                static fn (Booking $booking): array => ['booking' => $booking, 'journey' => $journeys->resolve($booking)],
                \array_slice($bookings, 0, 4),
            ),
            'booking_count' => \count($bookings),
            'upcoming_count' => ($statuses['pending'] ?? 0) + ($statuses['confirmed'] ?? 0),
            'completed_count' => $statuses['completed'] ?? 0,
            'drafts' => $draftRepository->findActiveFor($user),
        ]);
    }

    #[Route('/account/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function profile(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        AuditLogger $auditLogger,
    ): Response {
        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string|null $newPassword */
            $newPassword = $form->get('newPassword')->getData();
            if (null !== $newPassword && '' !== $newPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            // the signed-in user is this same object, so the session keeps up with the new email and password
            $entityManager->flush();
            $auditLogger->log('profile_updated', 'user', $user->getId());
            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
        }

        $view = $form->createView();
        if ($form->isSubmitted()) {
            // the form keeps the rejected input; the header goes back to the saved name
            $entityManager->refresh($user);
        }

        return $this->render('account/profile.html.twig', [
            'form' => $view,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
