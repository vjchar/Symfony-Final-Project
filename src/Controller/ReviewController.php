<?php

namespace App\Controller;

use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewType;
use App\Repository\ReviewRepository;
use App\Service\ReviewManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 rate-trip.php: review a completed rental, once per booking.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class ReviewController extends AbstractController
{
    #[Route('/account/rate-trip', name: 'app_rate_trip', methods: ['GET', 'POST'])]
    public function rateTrip(Request $request, #[CurrentUser] User $user, ReviewRepository $reviews, ReviewManager $manager): Response
    {
        $eligible = $reviews->findReviewableBookings($user);
        $form = $this->createForm(ReviewType::class);
        $form->handleRequest($request);

        $reference = trim($form->isSubmitted() ? (string) $form->get('reference')->getData() : $request->query->getString('reference'));
        if ('' === $reference) {
            $reference = isset($eligible[0]) ? $eligible[0]->getReference() : '';
        }
        $booking = null;
        foreach ($eligible as $candidate) {
            if ($candidate->getReference() === $reference) {
                $booking = $candidate;
                break;
            }
        }

        $errors = [];
        if ($form->isSubmitted()) {
            try {
                if (!$form->isValid()) {
                    throw new \InvalidArgumentException('Your session expired. Refresh the page and try again.');
                }
                $scores = [];
                foreach (['overall', ...array_keys(Review::CATEGORIES)] as $field) {
                    $scores[$field] = (string) $form->get($field)->getData();
                }
                $photos = array_values(array_filter((array) $form->get('photos')->getData(), static fn (mixed $file): bool => $file instanceof UploadedFile));
                $manager->submit(
                    $booking,
                    $user,
                    $scores,
                    (string) $form->get('title')->getData(),
                    (string) $form->get('body')->getData(),
                    '' !== (string) $form->get('consent')->getData(),
                    $photos,
                );
                $this->addFlash('success', 'Thank you. Your verified-trip review is awaiting moderation.');

                return $this->redirectToRoute('app_booking_show', ['reference' => $reference], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\DomainException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('review/rate_trip.html.twig', [
            'eligible' => $eligible,
            'booking' => $booking ?? ($eligible[0] ?? null),
            'reference' => $reference,
            'form' => $form->createView(),
            'submitted' => $form->isSubmitted() ? $request->getPayload()->all() : [],
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
