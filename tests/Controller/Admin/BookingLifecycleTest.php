<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Booking;
use App\Entity\CustomerDocument;
use App\Entity\MaintenanceRecord;
use App\Entity\Notification;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Service\Rental\RentalSweeper;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * The booking lifecycle around the customer's own requests: the admin status
 * controls (reject, cancel, no-show), the scheduled sweep (unpaid bookings
 * released, overdue rentals and uncollected pickups flagged), late-return
 * fees, the rules that keep bookings from being stranded (maintenance,
 * deactivated vehicles, a license that runs out mid-rental), and the email
 * copy of every notification. The booking is the three-day ₱18,600
 * Fortuner rental (₱6,200 a day) picked up in two days at 09:00.
 */
final class BookingLifecycleTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use RunsRentals;
    use UploadsFiles;

    private KernelBrowser $client;
    private User $customer;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->em()->createQuery('DELETE FROM '.MaintenanceRecord::class)->execute();
        $this->rememberVehicleStatus();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->em()->createQuery('DELETE FROM '.MaintenanceRecord::class)->execute();
        $this->em()->createQuery('UPDATE '.Vehicle::class.' v SET v.isActive = true WHERE v.slug = :slug')->execute(['slug' => 'toyota-fortuner']);
        $this->restoreVehicleStatus();
        parent::tearDown();
    }

    public function testARejectedPendingBookingIsRefundedInFullAndEmailed(): void
    {
        $booking = $this->pendingBookingWithVerifiedDeposit();

        $this->statusAction($booking, 'reject', '');
        self::assertSame('Give the customer a reason for rejecting this booking.', $this->flash());
        self::assertSame('pending', $this->reload($booking)->getStatus());

        $this->statusAction($booking, 'reject', 'Documents never arrived');
        // every notification is emailed once the request is over
        $subjects = array_map(static fn (Email $email): string => (string) $email->getSubject(), self::getMailerMessages());
        self::assertContains('Booking Rejected | VJ Car Rental', $subjects);
        self::assertSame('Booking rejected.', $this->flash());

        $booking = $this->reload($booking);
        self::assertSame('rejected', $booking->getStatus());
        self::assertNotNull($booking->getRejectedAt());
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        self::assertSame([PaymentRefund::TYPE_CANCELLATION, 5000], [$refund->getRefundType(), $refund->getAmount()]);
        self::assertStringContainsString('Reason: Documents never arrived', $this->notification($this->customer, 'Booking Rejected')->getMessage());
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Refund Pending');
    }

    public function testTheRentalTeamCanCancelAConfirmedBookingAndMarkANoShowAfterPickup(): void
    {
        $booking = $this->confirmedBooking();

        // no-show only once the pickup time has passed
        $this->client->request('GET', '/admin/bookings');
        self::assertSelectorNotExists(sprintf('form[action="/admin/bookings/%d/no-show"]', $booking->getId()));
        $this->moveTimes($booking, ['pickupAt' => '-2 hours']);
        $this->statusAction($booking, 'no-show', 'Did not answer calls');
        self::assertSame('Booking marked as a no-show.', $this->flash());
        $booking = $this->reload($booking);
        self::assertSame('no_show', $booking->getStatus());
        self::assertNotNull($booking->getNoShowAt());
        self::assertSame(0, $this->em()->getRepository(PaymentRefund::class)->count([]), 'a no-show is not refunded automatically');
        self::assertNotNull($this->notification($this->customer, 'Booking Closed — Vehicle Not Collected'));
        self::assertSame('available', $this->fortuner()->getAvailabilityStatus());

        // a second booking is cancelled by the team with a full refund
        $this->clearBookings();
        $this->clearCustomerRecords();
        $booking = $this->confirmedBooking();
        $this->statusAction($booking, 'cancel', 'Vehicle failed inspection');
        self::assertSame('Booking cancelled.', $this->flash());
        self::assertSame('cancelled', $this->reload($booking)->getStatus());
        self::assertSame(5000, $this->em()->getRepository(PaymentRefund::class)->findOneBy([])->getAmount());
        self::assertNotNull($this->notification($this->customer, 'Booking Cancelled by VJ Car Rental'));
    }

    public function testTheSweepReleasesUnpaidBookingsAndFlagsMissedPickups(): void
    {
        // an unpaid booking older than the 24-hour hold
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $unpaid = $this->bookings()[0];
        $this->moveTimes($unpaid, ['createdAt' => '-25 hours']);

        $result = $this->sweep();
        self::assertSame(1, $result->expiredBookings);
        self::assertSame('cancelled', $this->reload($unpaid)->getStatus());
        self::assertNotNull($this->notification($this->customer, 'Booking Expired'));

        // a deposit awaiting verification keeps the hold
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $submitted = $this->bookings()[0];
        $this->pay($submitted, 'deposit', 5000);
        $this->moveTimes($submitted, ['createdAt' => '-25 hours']);
        self::assertSame(0, $this->sweep()->expiredBookings);
        self::assertSame('pending', $this->reload($submitted)->getStatus());

        // a ready booking not collected an hour after pickup is flagged to the desk once
        $this->clearBookings();
        $this->clearCustomerRecords();
        $ready = $this->confirmedBooking();
        $this->submit('/admin/bookings', '#preparation-queue form');
        $this->moveTimes($ready, ['pickupAt' => '-2 hours']);
        self::assertSame(1, $this->sweep()->missedPickups);
        self::assertSame(0, $this->sweep()->missedPickups);
        self::assertSame(1, $this->notificationCount($this->admin, 'Vehicle not collected'));
        self::assertSame('ready', $this->reload($ready)->getStatus(), 'the desk decides whether it is a no-show');
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/rentals');
        self::assertSelectorTextContains('#rental-queue', 'Not collected');
    }

    public function testAnOverdueRentalIsFlaggedOnceAndChargedTheDailyRatePerLateDay(): void
    {
        $booking = $this->confirmedBooking();
        $this->submit('/admin/bookings', '#preparation-queue form');
        $this->deskAction($booking, 'checkout', ['odometer' => '500', 'fuel_percent' => '100', 'body_condition' => 'good']);
        // due back 26 hours ago: two started days late
        $this->moveTimes($booking, ['pickupAt' => '-4 days -2 hours', 'returnAt' => '-26 hours']);

        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('app:rentals:sweep'));
        $command->execute([]);
        self::assertStringContainsString('1 rental(s) flagged overdue', $command->getDisplay());
        self::assertSame(0, $this->sweep()->overdueRentals, 'flagged only once');
        self::assertStringContainsString('₱12,400 is due so far', $this->notification($this->customer, 'Rental Overdue')->getMessage());
        self::assertSame(1, $this->notificationCount($this->admin, 'Rental overdue'));

        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Rental Overdue');

        // check-in and settlement start from the late fee, excess mileage and refuelling
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/rentals?reference='.$booking->getReference());
        self::assertSelectorTextContains('#rental-queue', 'Overdue');
        self::assertInputValueSame('extra_charges', '12400');
        self::assertSelectorExists('form[data-controller="return-charges"][data-return-charges-km-allowance-value="600"][data-return-charges-km-rate-value="15"]');
        // 700 km on a 600 km allowance (200 a day for 3 days), 15% less fuel
        $this->deskAction($booking, 'checkin', ['odometer' => '1200', 'fuel_percent' => '85', 'body_condition' => 'good', 'extra_charges' => '16000']);
        $this->client->request('GET', '/admin/rentals?reference='.$booking->getReference());
        self::assertInputValueSame('late_charges', '12400');
        self::assertInputValueSame('fuel_charges', '1000');
        // what is left of the recorded ₱16,000: the ₱1,500 mileage and ₱1,100 of damage
        self::assertInputValueSame('other_charges', '2600');
        self::assertSelectorTextContains('.rental-settlement-form', '100 excess km: ₱1,500');
    }

    public function testMaintenanceAndDeactivationCannotStrandABooking(): void
    {
        $booking = $this->confirmedBooking();
        $vehicle = $this->fortuner();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/maintenance');
        $this->client->submitForm('Create Record', [
            'maintenance_record[vehicle]' => (string) $vehicle->getId(),
            'maintenance_record[title]' => 'Brake service',
            'maintenance_record[startsAt]' => $this->day(3).'T09:00',
            'maintenance_record[description]' => 'Replace the front brake pads.',
            'maintenance_record[cost]' => '4000',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Booking '.$booking->getReference().' still uses this vehicle');
        self::assertSame(0, $this->em()->getRepository(MaintenanceRecord::class)->count([]));

        // after the booking ends it can be scheduled
        $this->client->request('GET', '/admin/maintenance');
        $this->client->submitForm('Create Record', [
            'maintenance_record[vehicle]' => (string) $vehicle->getId(),
            'maintenance_record[title]' => 'Brake service',
            'maintenance_record[startsAt]' => $this->day(6).'T09:00',
            'maintenance_record[description]' => 'Replace the front brake pads.',
            'maintenance_record[cost]' => '4000',
        ]);
        self::assertResponseRedirects('/admin/maintenance', 303);

        // ...but not started early while the booking still needs the vehicle
        $record = $this->em()->getRepository(MaintenanceRecord::class)->findOneBy([]);
        $this->submit('/admin/maintenance', sprintf('form[action="/admin/maintenance/%d/status"]', $record->getId()), ['status' => MaintenanceRecord::STATUS_IN_PROGRESS]);
        self::assertStringContainsString($booking->getReference(), $this->flash());
        self::assertNotSame('maintenance', $this->fortuner()->getAvailabilityStatus());

        // the vehicle cannot be taken off the fleet either
        $crawler = $this->client->request('GET', '/admin/vehicles?edit='.$vehicle->getId());
        $form = $crawler->selectButton('Save Vehicle')->form();
        $form['vehicle[isActive]']->untick();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.admin-editor', 'This vehicle still has booking '.$booking->getReference());
        self::assertTrue($this->fortuner()->isActive());
    }

    public function testALicenseMustStayValidUntilTheReturn(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->pay($booking, 'deposit', 5000);
        // valid today, but expires the day before the return
        foreach ([['drivers_license', 'N01-23-456789', $this->day(4)], ['government_id', 'PSN-1234-5678', '']] as [$type, $number, $expiry]) {
            $crawler = $this->client->request('GET', '/account/documents');
            $this->client->request('POST', '/account/documents', [
                '_token' => $crawler->filter('.operation-upload input[name="_token"]')->attr('value'),
                'document_type' => $type,
                'document_number' => $number,
                'expiry_date' => $expiry,
            ], ['document_file' => $this->png('id.png')]);
        }
        $this->client->loginUser($this->admin);
        $this->em()->clear();
        foreach ($this->em()->getRepository(CustomerDocument::class)->findAll() as $document) {
            $this->submit('/admin/documents?status=all', sprintf('form[action^="/admin/documents/%d/review"]', $document->getId()), ['status' => 'approved']);
        }
        $this->verifyPayments();

        self::assertSame('pending', $this->reload($booking)->getStatus(), 'not confirmed with a license that runs out mid-rental');
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Renew Your Driver’s License');
    }

    public function testSetupStaysClosedWhileAnyAdministratorAccountExists(): void
    {
        $this->em()->createQuery('UPDATE '.User::class.' u SET u.status = :inactive WHERE u.role = :admin')->execute(['inactive' => User::STATUS_INACTIVE, 'admin' => User::ROLE_ADMIN]);
        $this->client->request('GET', '/setup');
        self::assertResponseRedirects('/login');
    }

    private function pendingBookingWithVerifiedDeposit(): Booking
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->pay($booking, 'deposit', 5000);
        $this->verifyPayments();
        self::assertSame('pending', $this->reload($booking)->getStatus(), 'documents are still missing');

        return $this->reload($booking);
    }

    private function statusAction(Booking $booking, string $action, string $note): void
    {
        $this->client->loginUser($this->admin);
        $this->submit('/admin/bookings', sprintf('form[action="/admin/bookings/%d/%s"]', $booking->getId(), $action), ['admin_note' => $note]);
        self::assertResponseRedirects('/admin/bookings#status-controls', 303);
    }

    /**
     * @param array<string, string> $changes field => relative time from now
     */
    private function moveTimes(Booking $booking, array $changes): void
    {
        foreach ($changes as $field => $relative) {
            $this->em()->createQuery(sprintf('UPDATE %s b SET b.%s = :at WHERE b.id = :id', Booking::class, $field))
                ->execute(['at' => new \DateTimeImmutable($relative), 'id' => $booking->getId()]);
        }
        $this->em()->clear();
    }

    private function sweep(): \App\Service\Rental\SweepResult
    {
        $result = static::getContainer()->get(RentalSweeper::class)->sweep();
        self::assertNotNull($result);
        $this->em()->clear();

        return $result;
    }

    private function notification(User $user, string $title): ?Notification
    {
        return $this->em()->getRepository(Notification::class)->findOneBy(['user' => $user->getId(), 'title' => $title]);
    }

    private function notificationCount(User $user, string $title): int
    {
        return $this->em()->getRepository(Notification::class)->count(['user' => $user->getId(), 'title' => $title]);
    }
}
