<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Booking;
use App\Entity\BookingModificationRequest;
use App\Entity\CustomerDocument;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\User;
use App\Service\Booking\BookingModificationManager;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin Payments, Admin Documents and Admin Reviews, driven the way the
 * customer and the administrator use them.
 */
final class VerificationTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use UploadsFiles;

    private KernelBrowser $client;
    private User $customer;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testApprovingTheLastRequirementConfirmsTheBooking(): void
    {
        $booking = $this->bookingWithDepositAndDocuments();

        // documents first: the deposit is still unverified, so nothing is confirmed yet
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/documents?status=pending');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.admin-table tbody tr');
        self::assertSelectorExists('.admin-nav a.active[href="/admin/documents"]');
        foreach ($this->documents() as $document) {
            $this->decide('/admin/documents', $document->getId(), 'review-document', ['status' => 'approved', 'admin_notes' => 'Clear scan']);
        }
        self::assertSame('pending', $this->booking($booking)->getStatus());
        self::assertSame(['approved', 'approved'], array_map(static fn (CustomerDocument $document): string => $document->getStatus(), $this->documents()));
        self::assertSame('Vina Admin', $this->documents()[0]->getVerifiedBy()?->getName());

        $this->client->request('GET', '/admin/payments');
        self::assertSelectorTextContains('.admin-table', $booking->getReference());
        self::assertSelectorTextContains('.admin-table', 'Cash at Branch');
        $this->decide('/admin/payments', $this->payments()[0]->getId(), 'review-payment', ['status' => 'paid', 'notes' => 'Counted at branch']);
        self::assertResponseRedirects('/admin/payments?status=all', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Payment status updated.');

        $payment = $this->payments()[0];
        self::assertSame([Payment::STATUS_PAID, 'Counted at branch', 'Vina Admin'], [$payment->getStatus(), $payment->getNotes(), $payment->getRecordedBy()?->getName()]);
        self::assertNotNull($payment->getPaidAt());
        self::assertSame('confirmed', $this->booking($booking)->getStatus());
        self::assertSame(1, $this->notifications('Requirements Verified — Vehicle Preparation'));
        self::assertSame(1, $this->notifications('Payment Verified'));

        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('.journey-next h2', 'We’re Preparing Your Vehicle');
        self::assertSelectorTextContains('#requirements', 'Verified');
        self::assertSelectorTextContains('.booking-summary-card', '₱5,000');
    }

    public function testVerifyingTheDepositFirstWaitsForTheDocuments(): void
    {
        $booking = $this->bookingWithDepositAndDocuments();
        $this->client->loginUser($this->admin);

        $this->decide('/admin/payments', $this->payments()[0]->getId(), 'review-payment', ['status' => 'paid']);
        self::assertSame('pending', $this->booking($booking)->getStatus());

        [$first, $second] = $this->documents();
        $this->decide('/admin/documents', $first->getId(), 'review-document', ['status' => 'approved']);
        self::assertSame('pending', $this->booking($booking)->getStatus());
        $this->decide('/admin/documents', $second->getId(), 'review-document', ['status' => 'approved']);
        self::assertSame('confirmed', $this->booking($booking)->getStatus(), 'the last document approval confirms it');
    }

    public function testRejectionsSendTheCustomerBackToFixIt(): void
    {
        $booking = $this->bookingWithDepositAndDocuments();
        $this->client->loginUser($this->admin);

        $this->decide('/admin/payments', $this->payments()[0]->getId(), 'review-payment', ['status' => 'failed', 'notes' => 'No matching transfer']);
        self::assertSame(1, $this->notifications('Payment Update Required'));
        $license = $this->documents()[0];
        $this->decide('/admin/documents', $license->getId(), 'review-document', ['status' => 'rejected', 'admin_notes' => 'Photo is blurred']);
        self::assertSame(1, $this->notifications('Document Update Required'));

        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('.journey-next h2', 'Payment Update Required');
        $this->client->request('GET', '/account/documents');
        self::assertSelectorTextContains('.review-note', 'Photo is blurred');
        self::assertSelectorExists('details.operation-upload[open]');
    }

    public function testInvalidDecisionsAreRefused(): void
    {
        $this->bookingWithDepositAndDocuments();
        $this->client->loginUser($this->admin);

        $this->decide('/admin/payments', $this->payments()[0]->getId(), 'review-payment', ['status' => 'refunded']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid payment status.');

        $this->decide('/admin/documents', $this->documents()[0]->getId(), 'review-document', ['status' => 'maybe']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Choose approve or reject.');

        $this->client->request('POST', '/admin/payments/'.$this->payments()[0]->getId().'/review', ['_token' => 'forged', 'status' => 'paid']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Your session expired.');
        self::assertSame(Payment::STATUS_PENDING, $this->payments()[0]->getStatus());
    }

    public function testCustomersCannotOpenTheAdminQueues(): void
    {
        $this->client->loginUser($this->customer);
        foreach (['/admin/payments', '/admin/documents', '/admin/reviews', '/admin/calendar', '/admin/reports', '/admin/health'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAVerifiedModificationPaymentAppliesTheChange(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];

        // the customer asks to keep the car two more days, which costs more
        $url = '/bookings/'.$booking->getReference().'/modify';
        $crawler = $this->client->request('GET', $url);
        $this->client->request('POST', $url, [
            '_token' => $crawler->filter('form.operation-card input[name="_token"]')->attr('value'),
            'vehicle' => 'toyota-fortuner',
            'pickup' => $this->day(2),
            'pickup_time' => '09:00',
            'return' => $this->day(7),
            'return_time' => '09:00',
            'pickup_method' => 'Branch pickup',
            'location' => 'Dumaguete City Branch',
            'delivery_address' => '',
            'promo' => '',
            'special_requests' => '',
            'reason' => 'Longer trip',
            'action' => 'submit_request',
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        $modification = $this->em()->getRepository(BookingModificationRequest::class)->findOneBy([]);
        static::getContainer()->get(BookingModificationManager::class)->review($modification, BookingModificationRequest::STATUS_APPROVED, $this->em()->find(User::class, $this->admin->getId()));
        self::assertSame(12400, $modification->getPriceDifference(), 'two more days at ₱6,200');

        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        self::assertSelectorExists('#paymentType option[value="modification"]');
        $this->client->request('POST', '/account/payments', [
            '_token' => $crawler->filter('#paymentForm input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'payment_type' => 'modification',
            'method' => 'cash',
            'amount' => '12400',
            'transaction_reference' => '',
        ]);
        self::assertResponseRedirects('/bookings/'.$booking->getReference(), 303);
        self::assertSame($this->day(5), $this->booking($booking)->getReturnAt()->format('Y-m-d'), 'nothing changes before the payment is verified');

        $this->client->loginUser($this->admin);
        $payment = $this->em()->getRepository(Payment::class)->findOneBy(['paymentType' => Payment::TYPE_MODIFICATION]);
        $this->decide('/admin/payments', $payment->getId(), 'review-payment', ['status' => 'paid']);

        $this->em()->clear();
        self::assertSame(BookingModificationRequest::STATUS_ACTIVATED, $this->em()->find(BookingModificationRequest::class, $modification->getId())->getStatus());
        self::assertSame($this->day(7), $this->booking($booking)->getReturnAt()->format('Y-m-d'));
        self::assertSame(31000, $this->booking($booking)->getTotal());
    }

    public function testReviewModerationPublishesTheReview(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->completeWithSettlement($booking);
        $crawler = $this->client->request('GET', '/account/rate-trip');
        $this->client->request('POST', '/account/rate-trip', [
            '_token' => $crawler->filter('.rating-form input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'overall' => '4', 'cleanliness' => '5', 'comfort' => '4', 'vehicle_condition' => '4', 'pickup_experience' => '3', 'customer_support' => '5',
            'title' => 'Great family SUV',
            'body' => 'Roomy, clean and easy to drive around Negros.',
            'consent' => '1',
        ], ['photos' => [$this->png('trip.png')]]);
        self::assertResponseRedirects();
        $review = $this->em()->getRepository(Review::class)->findOneBy([]);

        // not public until approved
        $this->client->request('GET', '/vehicles/toyota-fortuner');
        self::assertSelectorNotExists('.review-card');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/reviews');
        self::assertSelectorTextContains('.admin-review-card h2', 'Great family SUV');
        self::assertSelectorExists('.admin-review-photos img[src^="/files/review-photo/"]');
        $this->decide('/admin/reviews', $review->getId(), 'moderate-review', ['status' => 'approved'], '/moderate');
        self::assertResponseRedirects('/admin/reviews?status=all', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Review status updated to Approved.');
        self::assertSame(1, $this->notifications('Review approved'));

        $this->client->request('GET', '/vehicles/toyota-fortuner');
        self::assertSelectorTextContains('.review-card h3', 'Great family SUV');
        self::assertSelectorTextContains('.rating-score-card', '4.0');
        self::assertSelectorTextContains('.page-hero-meta', '4.0 from 1 verified review');
        $this->client->request('GET', '/vehicles');
        self::assertSelectorExists('.vehicle-rating[aria-label="Rating 4.0 out of 5"]');
    }

    /**
     * Sign in as the customer, book, pay the deposit in cash and upload both documents.
     */
    private function bookingWithDepositAndDocuments(): Booking
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];

        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        $this->client->request('POST', '/account/payments', [
            '_token' => $crawler->filter('#paymentForm input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'payment_type' => 'deposit',
            'method' => 'cash',
            'amount' => '5000',
            'transaction_reference' => '',
        ]);
        foreach ([['drivers_license', 'N01-23-456789', $this->day(400)], ['government_id', 'PSN-1234-5678', '']] as [$type, $number, $expiry]) {
            $crawler = $this->client->request('GET', '/account/documents');
            $this->client->request('POST', '/account/documents', [
                '_token' => $crawler->filter('.operation-upload input[name="_token"]')->attr('value'),
                'document_type' => $type,
                'document_number' => $number,
                'expiry_date' => $expiry,
            ], ['document_file' => $this->png('id.png')]);
            self::assertResponseRedirects();
        }

        return $booking;
    }

    /**
     * Submits one admin decision form from its queue page, the way the browser does.
     *
     * @param array<string, string> $fields
     */
    private function decide(string $queue, ?int $id, string $tokenPrefix, array $fields, string $action = '/review'): void
    {
        $crawler = $this->client->request('GET', $queue.'?status=all');
        $form = $crawler->filter(sprintf('form[action^="%s/%d%s"]', $queue, $id, $action));
        self::assertCount(1, $form, sprintf('a %s form for #%d', $tokenPrefix, $id));
        $this->client->request('POST', (string) $form->attr('action'), $fields + ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
    }

    private function booking(Booking $booking): Booking
    {
        $this->em()->clear();

        return $this->em()->find(Booking::class, $booking->getId());
    }

    /** @return list<Payment> */
    private function payments(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(Payment::class)->findBy([], ['id' => 'ASC']);
    }

    /** @return list<CustomerDocument> */
    private function documents(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(CustomerDocument::class)->findBy([], ['documentType' => 'ASC']);
    }

    private function notifications(string $title): int
    {
        return $this->em()->getRepository(Notification::class)->count(['user' => $this->customer->getId(), 'title' => $title]);
    }
}
