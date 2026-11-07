<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Review;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ReviewTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use UploadsFiles;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->loginAsAdmin($this->client);
        $this->client->loginUser($this->createUser('juan@test.vj', name: 'Juan Dela Cruz'));
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testOnlyCompletedBookingsCanBeRated(): void
    {
        $this->submitBooking($this->client);

        $this->client->request('GET', '/account/rate-trip');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state h2', 'No trips are ready for review');
    }

    public function testACompletedTripIsReviewedOnceWithPhotos(): void
    {
        $booking = $this->completedBooking();

        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('.journey-next h2', 'Rate Your Rental');

        $token = $this->csrfToken();
        $this->review($booking, photos: [$this->png('trip-1.png'), $this->png('trip-2.png')], token: $token);
        self::assertResponseRedirects('/bookings/'.$booking->getReference(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Thank you. Your verified-trip review is awaiting moderation.');
        self::assertSelectorTextContains('.journey-next h2', 'Rental Complete');

        $this->em()->clear();
        $review = $this->em()->getRepository(Review::class)->findOneBy([]);
        self::assertSame([5, 4, Review::STATUS_PENDING], [$review->getOverall(), $review->getComfort(), $review->getStatus()]);
        self::assertCount(2, $review->getPhotos());
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['title' => 'Review awaiting moderation']));

        // the booking is no longer offered, and a second review is refused
        $this->client->request('GET', '/account/rate-trip');
        self::assertSelectorTextContains('.empty-state h2', 'No trips are ready for review');
        $this->review($booking, token: $token);
        self::assertSelectorTextContains('.alert-danger', 'This completed booking is not eligible for another review.');
        self::assertSame(1, $this->em()->getRepository(Review::class)->count([]));
    }

    public function testReviewRulesFromVersion15(): void
    {
        $booking = $this->completedBooking();

        $this->review($booking, ['cleanliness' => '6']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Choose a score from 1 to 5 for every rating category.');
        self::assertSelectorExists('#reviewTitle[value="Smooth family trip"]', 'the form keeps what was typed');

        $this->review($booking, ['body' => 'Too short.']);
        self::assertSelectorTextContains('.alert-danger', 'Write a review between 20 and 3,000 characters.');

        $this->review($booking, ['consent' => null]);
        self::assertSelectorTextContains('.alert-danger', 'Confirm that the review describes your own rental.');

        $this->review($booking, photos: [$this->png('1.png'), $this->png('2.png'), $this->png('3.png'), $this->png('4.png')]);
        self::assertSelectorTextContains('.alert-danger', 'Upload no more than three review photos.');

        $this->review($booking, photos: [$this->upload('%PDF-1.4 not a photo', 'trip.png', 'image/png')]);
        self::assertSelectorTextContains('.alert-danger', 'The uploaded file type is not allowed.');

        self::assertSame(0, $this->em()->getRepository(Review::class)->count([]), 'a refused photo leaves no review behind');
    }

    private function completedBooking(): Booking
    {
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->em()->createQuery('UPDATE '.Booking::class.' b SET b.status = :completed')->execute(['completed' => 'completed']);

        return $booking;
    }

    /**
     * @param array<string, string|null> $fields null leaves a field out
     * @param list<UploadedFile>         $photos
     */
    private function review(Booking $booking, array $fields = [], array $photos = [], ?string $token = null): void
    {
        $this->client->request('POST', '/account/rate-trip', array_filter($fields + [
            '_token' => $token ?? $this->csrfToken(),
            'reference' => $booking->getReference(),
            'overall' => '5',
            'cleanliness' => '5',
            'comfort' => '4',
            'vehicle_condition' => '5',
            'pickup_experience' => '4',
            'customer_support' => '5',
            'title' => 'Smooth family trip',
            'body' => 'The Fortuner was spotless and the pickup took five minutes.',
            'consent' => '1',
        ], static fn (?string $value): bool => null !== $value), ['photos' => $photos]);
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/account/rate-trip');

        return (string) $crawler->filter('.rating-form input[name="_token"]')->attr('value');
    }
}
