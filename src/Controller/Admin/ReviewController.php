<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Service\ReviewManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-reviews.php: publish or reject verified-trip reviews.
 */
#[IsGranted('ROLE_ADMIN')]
final class ReviewController extends AbstractController
{
    public const FILTERS = ['all', ...Review::STATUSES];

    #[Route('/admin/reviews', name: 'app_admin_reviews', methods: ['GET'])]
    public function index(ReviewRepository $reviews, #[MapQueryParameter] string $status = Review::STATUS_PENDING): Response
    {
        // v15 opens the pending queue, and falls back to it for an unknown filter
        $filter = \in_array($status, self::FILTERS, true) ? $status : Review::STATUS_PENDING;

        return $this->render('admin/review/index.html.twig', [
            'reviews' => $reviews->findForAdmin($filter),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'statuses' => Review::STATUSES,
        ]);
    }

    #[Route('/admin/reviews/{id}/moderate', name: 'app_admin_review_moderate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moderate(int $id, Request $request, #[CurrentUser] User $admin, ReviewRepository $reviews, ReviewManager $manager): Response
    {
        $payload = $request->getPayload();
        $review = $reviews->find($id);
        if (!$this->isCsrfTokenValid('moderate-review-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $review) {
            $this->addFlash('danger', 'Review not found.');
        } else {
            try {
                $status = $payload->getString('status');
                $manager->moderate($review, $status, $admin);
                $this->addFlash('success', sprintf('Review status updated to %s.', ucfirst($status)));
            } catch (\InvalidArgumentException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_reviews', ['status' => $request->query->getString('status', 'all')], Response::HTTP_SEE_OTHER);
    }
}
