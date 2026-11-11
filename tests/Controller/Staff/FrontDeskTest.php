<?php

namespace App\Tests\Controller\Staff;

use App\Entity\AuditLog;
use App\Entity\Booking;
use App\Entity\ContactMessage;
use App\Entity\CustomerDocument;
use App\Entity\RentalInspection;
use App\Entity\User;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The staff front desk: preparing vehicles, the pickup and return
 * inspections, no-shows, document checks and the enquiry inbox, driven
 * through the staff pages. Money (settlement, refunds) stays with the admin.
 */
final class FrontDeskTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use RunsRentals;
    use UploadsFiles;

    private KernelBrowser $client;
    private User $customer;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->em()->createQuery('DELETE FROM '.ContactMessage::class)->execute();
        $this->rememberVehicleStatus();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
        $this->staff = $this->createUser('desk@test.vj', User::ROLE_STAFF, name: 'Dina Desk');
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->em()->createQuery('DELETE FROM '.ContactMessage::class)->execute();
        $this->restoreVehicleStatus();
        parent::tearDown();
    }

    public function testStaffSignInToTheirOverviewWithTheStaffMenu(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form[method="post"]')->form([
            'email' => 'desk@test.vj',
            'password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/staff', 303);

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hello, Dina');
        $menu = $crawler->filter('#adminSidebar .admin-nav__links a')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertSame(['/staff', '/staff/bookings', '/staff/rentals', '/staff/documents', '/staff/messages', '/staff/maintenance', '/account/notifications', '/account/profile'], $menu);
        self::assertSelectorTextContains('#adminSidebar a.active', 'Overview');
    }

    public function testStaffPrepareHandOverAndTakeBackARental(): void
    {
        $booking = $this->confirmedBooking();
        $reference = $booking->getReference();
        $this->client->loginUser($this->staff);

        $this->client->request('GET', '/staff');
        self::assertSelectorTextContains('#today-preparation', $reference);

        // preparation
        $this->submit('/staff/bookings', '#preparation-queue form', ['note' => 'Washed and fuelled']);
        self::assertResponseRedirects('/staff/bookings#preparation-queue', 303);
        self::assertSame('Vehicle marked ready. The customer has been notified.', $this->flash());
        self::assertSame('ready', $this->reload($booking)->getStatus());
        $this->client->request('GET', '/staff');
        self::assertSelectorTextContains('#today-pickups', $reference);

        // pickup inspection
        $this->inspect($booking, 'checkout', ['odometer' => '12000', 'fuel_percent' => '100', 'body_condition' => 'excellent']);
        self::assertSame('Pickup inspection saved. The rental is now active.', $this->flash());
        self::assertSame('active', $this->reload($booking)->getStatus());
        self::assertSame('rented', $this->fortuner()->getAvailabilityStatus());

        // return inspection: the odometer cannot go backwards
        $this->inspect($booking, 'checkin', ['odometer' => '11000', 'fuel_percent' => '80', 'body_condition' => 'good', 'extra_charges' => '0']);
        self::assertSame('Return odometer cannot be below the checkout odometer.', $this->flash());
        $this->inspect($booking, 'checkin', ['odometer' => '12300', 'fuel_percent' => '80', 'body_condition' => 'good', 'damage_notes' => 'Small scratch', 'extra_charges' => '0']);
        self::assertSame('Return inspection saved. An administrator will settle the deposit.', $this->flash());
        self::assertSame('returned', $this->reload($booking)->getStatus());

        // both inspections are recorded by the staff member
        $inspections = $this->em()->getRepository(RentalInspection::class)->findBy(['booking' => $booking->getId()], ['id' => 'ASC']);
        self::assertSame([12000, 12300], array_map(static fn (RentalInspection $inspection): int => $inspection->getOdometer(), $inspections));
        self::assertSame([$this->staff->getId(), $this->staff->getId()], array_map(static fn (RentalInspection $inspection): ?int => $inspection->getRecordedBy()->getId(), $inspections));

        // the settlement is left to the administrator
        $crawler = $this->client->request('GET', '/staff/rentals?reference='.$reference);
        self::assertSelectorTextContains('#rental-desk .alert', 'An administrator settles the security deposit');
        self::assertCount(0, $crawler->filter('#rental-desk form'));
        self::assertSelectorTextContains('[data-inspection="checkin"]', '12,300 km');
        $this->client->request('GET', '/staff');
        self::assertSelectorTextContains('#awaiting-settlement', $reference);

        // the audit trail names the staff member, their role and what they recorded
        $staffId = $this->staff->getId();
        $ready = $this->audit('vehicle_ready_for_pickup', $booking->getId());
        self::assertEquals(['staff_id' => $staffId, 'pickup_method' => 'Branch pickup'], $ready->getMetadata());
        self::assertSame([$staffId, User::ROLE_STAFF], [$ready->getUser()->getId(), $ready->getActorRole()]);
        self::assertEquals(
            ['from' => 'ready', 'to' => 'active', 'odometer' => 12000, 'fuel_percent' => 100, 'condition' => 'excellent', 'staff_id' => $staffId],
            $this->audit('rental_checked_out', $booking->getId())->getMetadata(),
        );
        self::assertEquals(
            ['from' => 'active', 'to' => 'returned', 'odometer' => 12300, 'fuel_percent' => 80, 'condition' => 'good', 'damage_notes' => 'Small scratch', 'extra_charges' => 0, 'staff_id' => $staffId],
            $this->audit('rental_checked_in', $booking->getId())->getMetadata(),
        );
        foreach ($this->em()->getRepository(AuditLog::class)->findBy(['entityType' => 'booking', 'entityId' => $booking->getId(), 'user' => $staffId]) as $entry) {
            self::assertArrayNotHasKey('admin_id', $entry->getMetadata(), $entry->getAction().' must not file a staff action as an admin\'s');
        }

        // and the administrator can list everything staff did
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/audit?role=staff&type=booking&record='.$booking->getId());
        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.audit-table tbody tr td:nth-child(2)')->each(static fn ($cell): string => $cell->text());
        self::assertSame(['Rental Checked In', 'Rental Checked Out', 'Booking Status Updated', 'Vehicle Ready For Pickup'], $actions);
        self::assertSame(4, $crawler->filter('.audit-table .audit-role--staff')->count());
        self::assertSelectorTextContains('.audit-table tbody tr', 'Dina Desk');
    }

    public function testMoneyAndAdministrationStayWithTheAdministrator(): void
    {
        $booking = $this->confirmedBooking();
        $this->client->loginUser($this->staff);

        foreach (['/admin', '/admin/rentals', '/admin/bookings', '/admin/payments', '/admin/users'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $this->client->request('POST', sprintf('/admin/bookings/%d/cancel', $booking->getId()), ['admin_note' => 'Staff try']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('confirmed', $this->reload($booking)->getStatus());

        // and the staff pages are not for customers
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/staff/bookings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffCloseAnUncollectedBookingAsANoShow(): void
    {
        $booking = $this->confirmedBooking();
        $this->em()->createQuery('UPDATE '.Booking::class.' b SET b.pickupAt = :past WHERE b.id = :id')
            ->execute(['past' => new \DateTimeImmutable('-3 hours'), 'id' => $booking->getId()]);
        $this->client->loginUser($this->staff);

        $this->client->request('GET', '/staff/bookings');
        self::assertSelectorTextContains('#uncollected', $booking->getReference());
        $this->submit('/staff/bookings', '#uncollected form', ['note' => 'No answer on the phone']);
        self::assertResponseRedirects('/staff/bookings#uncollected', 303);
        self::assertSame('Booking marked as a no-show.', $this->flash());

        $booking = $this->reload($booking);
        self::assertSame('no_show', $booking->getStatus());
        self::assertSame('No answer on the phone', $booking->getAdminNotes());
    }

    public function testStaffOpenAndVerifyCustomerDocuments(): void
    {
        $this->client->loginUser($this->customer);
        $crawler = $this->client->request('GET', '/account/documents');
        $this->client->request('POST', '/account/documents', [
            '_token' => $crawler->filter('.operation-upload input[name="_token"]')->attr('value'),
            'document_type' => 'drivers_license',
            'document_number' => 'N01-23-456789',
            'expiry_date' => $this->day(400),
        ], ['document_file' => $this->png('license.png')]);
        $document = $this->em()->getRepository(CustomerDocument::class)->findOneBy([]);
        self::assertSame(CustomerDocument::STATUS_PENDING, $document->getStatus());

        $this->client->loginUser($this->staff);
        $crawler = $this->client->request('GET', '/staff/documents');
        self::assertSelectorTextContains(sprintf('tr[data-document="%d"]', $document->getId()), 'Juan Dela Cruz');

        // staff can open the private file they are checking
        $this->client->request('GET', $crawler->filter(sprintf('tr[data-document="%d"] a[href^="/files/document/"]', $document->getId()))->attr('href'));
        self::assertResponseIsSuccessful();

        $this->submit('/staff/documents', sprintf('form[action^="/staff/documents/%d/review"]', $document->getId()), ['status' => 'approved', 'notes' => 'Matches the customer']);
        self::assertSame('Document review saved.', $this->flash());
        $this->em()->clear();
        $document = $this->em()->find(CustomerDocument::class, $document->getId());
        self::assertSame(CustomerDocument::STATUS_APPROVED, $document->getStatus());
        self::assertSame($this->staff->getId(), $document->getVerifiedBy()->getId());
        $review = $this->audit('customer_document_approved', $document->getId());
        self::assertEquals(['document_type' => 'drivers_license', 'customer_id' => $this->customer->getId(), 'notes' => 'Matches the customer', 'staff_id' => $this->staff->getId()], $review->getMetadata());
        self::assertSame(User::ROLE_STAFF, $review->getActorRole());

        // another customer still cannot open it
        $this->client->loginUser($this->createUser('other@test.vj', name: 'Other Customer'));
        $this->client->request('GET', '/files/document/'.$document->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaffAnswerEnquiries(): void
    {
        $message = (new ContactMessage())
            ->setName('Ana Reyes')
            ->setEmail('ana@test.vj')
            ->setPhone('')
            ->setSubject('Airport delivery')
            ->setMessage('Can you deliver a car to the airport?')
            ->setStatus('new');
        $this->em()->persist($message);
        $this->em()->flush();
        $this->client->loginUser($this->staff);

        $this->client->request('GET', '/staff/messages');
        self::assertSelectorTextContains('.admin-message-card h2', 'Airport delivery');

        $this->submit('/staff/messages', 'form.contact-reply-form', ['reply' => 'Yes, we deliver to the airport for a small fee.']);
        self::assertEmailCount(1);
        self::assertSame('Reply sent to ana@test.vj.', $this->flash());

        $this->submit('/staff/messages?status=all', sprintf('form[action^="/staff/messages/%d/status"]', $message->getId()), ['status' => 'closed']);
        self::assertSame('Enquiry status updated.', $this->flash());

        $this->em()->clear();
        $message = $this->em()->find(ContactMessage::class, $message->getId());
        self::assertSame('closed', $message->getStatus());
        self::assertSame($this->staff->getId(), $message->getRepliedBy()->getId());
        $staffId = $this->staff->getId();
        $reply = $this->audit('contact_message_replied', $message->getId());
        self::assertSame($staffId, $reply->getMetadata()['staff_id']);
        self::assertSame(User::ROLE_STAFF, $reply->getActorRole());
        self::assertEquals(
            ['from' => $reply->getMetadata()['status'], 'status' => 'closed', 'staff_id' => $staffId],
            $this->audit('contact_message_updated', $message->getId())->getMetadata(),
        );
    }

    private function audit(string $action, ?int $entityId): AuditLog
    {
        $this->em()->clear();
        $entry = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => $action, 'entityId' => $entityId], ['id' => 'DESC']);
        self::assertNotNull($entry, 'audit entry '.$action);

        return $entry;
    }

    /**
     * @param array<string, string> $fields
     */
    private function inspect(Booking $booking, string $action, array $fields): void
    {
        $page = '/staff/rentals?reference='.$booking->getReference();
        $this->submit($page, sprintf('form[action="/staff/rentals/%s/%s"]', $booking->getReference(), $action), $fields + ['notes' => '', 'damage_notes' => '']);
        self::assertResponseRedirects($page, 303);
    }
}
