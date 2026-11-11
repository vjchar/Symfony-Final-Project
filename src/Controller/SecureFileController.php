<?php

namespace App\Controller;

use App\Entity\CustomerDocument;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\User;
use App\Service\PrivateFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 secure-file.php and review-photo.php: private uploads are served only
 * to their owner and to administrators; customer documents also to staff, who
 * verify them. Anything else is a plain 404, so the response never reveals
 * whether a file exists.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class SecureFileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrivateFileStorage $storage,
    ) {
    }

    #[Route('/files/document/{id}', name: 'app_file_document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(int $id, #[CurrentUser] User $user): Response
    {
        $document = $this->entityManager->find(CustomerDocument::class, $id);

        return $this->serve($user, $document?->getUser(), PrivateFileStorage::DOCUMENTS, (string) $document?->getFilename(), staffMayView: true);
    }

    #[Route('/files/payment/{id}', name: 'app_file_payment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function paymentProof(int $id, #[CurrentUser] User $user): Response
    {
        $payment = $this->entityManager->find(Payment::class, $id);

        return $this->serve($user, $payment?->getUser(), PrivateFileStorage::PAYMENT_PROOFS, (string) $payment?->getProofFilename());
    }

    #[Route('/files/review-photo/{id}/{index}', name: 'app_file_review_photo', requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    public function reviewPhoto(int $id, int $index, #[CurrentUser] User $user): Response
    {
        $review = $this->entityManager->find(Review::class, $id);
        $filename = $review?->getPhoto($index);

        return $this->serve($user, null === $filename ? null : $review->getUser(), PrivateFileStorage::REVIEWS, (string) $filename);
    }

    private function serve(User $viewer, ?User $owner, string $area, string $filename, bool $staffMayView = false): Response
    {
        $allowed = null !== $owner && (
            $viewer->isAdmin()
            || ($staffMayView && $viewer->isStaff())
            || $owner->getId() === $viewer->getId()
        );
        $path = $allowed ? $this->storage->path($area, $filename) : null;
        if (null === $path) {
            throw $this->createNotFoundException('File not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
