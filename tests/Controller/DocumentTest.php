<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\CustomerDocument;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\Booking\BookingJourneyResolver;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use UploadsFiles;

    private KernelBrowser $client;
    private User $customer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
        $this->client->loginUser($this->customer);
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testBothDocumentsMoveTheBookingToVerification(): void
    {
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->payCashDeposit($booking);

        $this->client->request('GET', '/account/documents');
        self::assertSelectorTextContains('.journey-next h2', 'Upload Required Documents');
        self::assertSelectorTextContains('.journey-next p', 'Next required document: Driver’s License.');
        self::assertSelectorCount(2, '.operation-card .status-badge--secondary');

        $this->submitDocument(['document_type' => 'drivers_license', 'document_number' => 'N01-23-456789', 'expiry_date' => $this->day(400), 'booking_reference' => $booking->getReference()]);
        self::assertResponseRedirects('/account/documents?reference='.$booking->getReference().'&uploaded=1', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Document uploaded securely and sent for verification.');
        self::assertSelectorTextContains('.operation-meta', '•••• 6789');
        self::assertSelectorTextContains('.journey-next p', 'Next required document: Government ID.');

        $this->submitDocument(['document_type' => 'government_id', 'document_number' => 'PSN-1234-5678']);
        self::assertSame('verification', $this->journey($booking)->stage);

        $documents = $this->em()->getRepository(CustomerDocument::class)->findBy(['user' => $this->customer]);
        self::assertCount(2, $documents);
        self::assertSame([CustomerDocument::STATUS_PENDING, CustomerDocument::STATUS_PENDING], array_map(static fn (CustomerDocument $document): string => $document->getStatus(), $documents));
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['user' => $this->customer, 'title' => 'Documents Submitted — Verification Pending']));
    }

    public function testDocumentRulesFromVersion15(): void
    {
        $this->submitDocument(['document_type' => 'drivers_license', 'document_number' => 'N01-23-456789', 'expiry_date' => $this->day(-1)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'The driver’s license must have a future expiry date.');

        $this->submitDocument(['document_type' => 'government_id', 'document_number' => 'ab']);
        self::assertSelectorTextContains('.alert-danger', 'Enter a valid document number.');

        $this->submitDocument(['document_type' => 'passport', 'document_number' => 'P1234567']);
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid document type.');

        $this->submitDocument(['document_type' => 'government_id', 'document_number' => 'PSN-1234-5678'], $this->upload('<?php echo 1;', 'id.png', 'image/png'));
        self::assertSelectorTextContains('.alert-danger', 'The uploaded file type is not allowed.');

        self::assertSame(0, $this->em()->getRepository(CustomerDocument::class)->count([]));
    }

    public function testAReplacementGoesBackToPendingAndOnlyTheOwnerCanOpenIt(): void
    {
        $this->submitDocument(['document_type' => 'government_id', 'document_number' => 'PSN-1234-5678']);
        $first = $this->document()->getFilename();
        $this->submitDocument(['document_type' => 'government_id', 'document_number' => 'PSN-9999-0000']);

        $document = $this->document();
        self::assertSame('PSN-9999-0000', $document->getDocumentNumber());
        self::assertNotSame($first, $document->getFilename());
        self::assertSame(1, $this->em()->getRepository(CustomerDocument::class)->count([]), 'one row per customer and document type');

        $this->client->request('GET', '/files/document/'.$document->getId());
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);

        $this->client->loginUser($this->createUser('maria@test.vj'));
        $this->client->request('GET', '/files/document/'.$document->getId());
        self::assertResponseStatusCodeSame(404);

        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/files/document/'.$document->getId());
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitDocument(array $fields, ?UploadedFile $file = null): void
    {
        $crawler = $this->client->request('GET', '/account/documents');
        $token = $crawler->filter('.operation-upload input[name="_token"]')->attr('value');

        $this->client->request('POST', '/account/documents', $fields + ['_token' => $token, 'expiry_date' => ''], ['document_file' => $file ?? $this->png('id.png')]);
    }

    private function payCashDeposit(Booking $booking): void
    {
        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        $this->client->request('POST', '/account/payments', [
            '_token' => $crawler->filter('#paymentForm input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'payment_type' => 'deposit',
            'method' => 'cash',
            'amount' => (string) $booking->getDeposit(),
            'transaction_reference' => '',
        ]);
        self::assertResponseRedirects();
    }

    private function document(): CustomerDocument
    {
        $this->em()->clear();

        return $this->em()->getRepository(CustomerDocument::class)->findOneBy([]);
    }

    private function journey(Booking $booking): \App\Service\Booking\BookingJourney
    {
        $this->em()->clear();

        return static::getContainer()->get(BookingJourneyResolver::class)->resolve($this->em()->find(Booking::class, $booking->getId()));
    }
}
