<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Service\Booking\RunsInTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * v15 rate-trip.php and submit_vehicle_review(): one moderated review per
 * completed booking, with up to three trip photos. (v15 also waits for the
 * return settlement to be settled; settlements come with the admin rental
 * workflow, so a completed booking is enough for now.)
 */
final class ReviewManager
{
    use RunsInTransaction;

    public const MAX_PHOTOS = 3;
    public const MAX_PHOTO_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewRepository $reviews,
        private readonly PrivateFileStorage $storage,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * v15 moderate_review(): the customer is told the decision.
     *
     * @throws \InvalidArgumentException with the v15 message
     */
    public function moderate(Review $review, string $status, User $admin): void
    {
        $from = $review->getStatus();
        $review->moderate($status);
        $booking = $review->getBooking();
        $this->notifications->notifyUser(
            $review->getUser(),
            'Review '.$status,
            sprintf('Your review for booking %s was marked %s.', $booking->getReference(), $status),
            'review',
            $booking,
        );
        $this->entityManager->flush();
        $this->auditLogger->log('review_moderated', 'review', $review->getId(), ['from' => $from, 'to' => $status], $admin);
    }

    /**
     * @param array<string, string> $scores  field => submitted score, for 'overall' and each Review::CATEGORIES key
     * @param list<UploadedFile>    $photos
     *
     * @throws \InvalidArgumentException|\DomainException with the v15 message
     */
    public function submit(?Booking $booking, User $user, array $scores, string $title, string $body, bool $consent, array $photos): Review
    {
        if (null === $booking || !$booking->isOwnedBy($user) || 'completed' !== $booking->getStatus() || null !== $this->reviews->findForBooking($booking)) {
            throw new \DomainException('This completed booking is not eligible for another review.');
        }

        $valid = [];
        foreach (['overall', ...array_keys(Review::CATEGORIES)] as $field) {
            $score = filter_var($scores[$field] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
            if (false === $score) {
                throw new \InvalidArgumentException('Choose a score from 1 to 5 for every rating category.');
            }
            $valid[$field] = $score;
        }
        $title = trim($title);
        $body = trim($body);
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            throw new \InvalidArgumentException('Use a review title between 5 and 160 characters.');
        }
        if (mb_strlen($body) < 20 || mb_strlen($body) > 3000) {
            throw new \InvalidArgumentException('Write a review between 20 and 3,000 characters.');
        }
        if (!$consent) {
            throw new \InvalidArgumentException('Confirm that the review describes your own rental.');
        }
        if (\count($photos) > self::MAX_PHOTOS) {
            throw new \InvalidArgumentException('Upload no more than three review photos.');
        }

        /** @var array{overall: int, cleanliness: int, comfort: int, vehicle_condition: int, pickup_experience: int, customer_support: int} $valid */
        $review = new Review($booking, $user, $valid, $title, $body);
        $saved = [];
        try {
            // like v15: the review and its photos are saved together or not at all
            $this->transactional(function () use ($review, $photos, $booking, &$saved): void {
                $this->entityManager->persist($review);
                $this->entityManager->flush();
                foreach ($photos as $photo) {
                    $saved[] = $filename = $this->storage->store($photo, PrivateFileStorage::REVIEWS, 'review-'.$review->getId(), PrivateFileStorage::PHOTO_TYPES, self::MAX_PHOTO_BYTES);
                    $review->addPhoto($filename);
                }
                $this->notifications->notifyAdmins(
                    'Review awaiting moderation',
                    sprintf('A verified-trip review for booking %s is ready for moderation.', $booking->getReference()),
                    'review',
                    $booking,
                );
                $this->entityManager->flush();
            });
        } catch (\Throwable $error) {
            foreach ($saved as $filename) {
                $this->storage->delete(PrivateFileStorage::REVIEWS, $filename);
            }
            // rolled back: the page renders the form again, so only the unsaved review leaves the entity manager
            $this->entityManager->detach($review);
            throw $error;
        }

        $this->auditLogger->log('review_submitted', 'review', $review->getId(), ['booking_id' => $booking->getId()]);

        return $review;
    }
}
