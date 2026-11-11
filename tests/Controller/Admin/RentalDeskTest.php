<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\PaymentRefund;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\RentalInspection;
use App\Entity\RentalSettlement;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * v15 admin-bookings.php "ready" and admin-rentals.php: preparation,
 * checkout, check-in, return settlement and completion, driven through the
 * admin pages, with what the customer sees at each step.
 */
final class RentalDeskTest extends WebTestCase
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
        $this->rememberVehicleStatus();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        $this->restoreVehicleStatus();
        parent::tearDown();
    }

    public function testARentalGoesFromPreparationToReview(): void
    {
        $booking = $this->confirmedBooking();
        $reference = $booking->getReference();

        // preparation: the confirmed booking waits on the admin bookings page
        $this->client->request('GET', '/admin/bookings');
        self::assertSelectorTextContains('#preparation-queue', $reference);
        $this->submit('/admin/bookings', '#preparation-queue form', ['admin_note' => 'Washed and fuelled']);
        self::assertResponseRedirects('/admin/bookings#preparation-queue', 303);
        $booking = $this->reload($booking);
        self::assertSame('ready', $booking->getStatus());
        self::assertNotNull($booking->getReadyAt());
        self::assertSame('Washed and fuelled', $booking->getAdminNotes());
        self::assertSame('reserved', $this->fortuner()->getAvailabilityStatus());
        self::assertSame(1, $this->notifications('Your Vehicle Is Ready for Pickup'));
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertSelectorTextContains('.journey-card, .next-step-card, main', 'Ready for Pickup');

        // checkout
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/rentals');
        self::assertSelectorTextContains('#rental-queue', $reference);
        $this->deskAction($booking, 'checkout', ['odometer' => '12000', 'fuel_percent' => '100', 'body_condition' => 'excellent', 'notes' => 'Spare tyre checked', 'damage_notes' => '']);
        self::assertSame('Checkout inspection saved and rental activated.', $this->flash());
        $booking = $this->reload($booking);
        self::assertSame('active', $booking->getStatus());
        self::assertNotNull($booking->getStartedAt());
        self::assertSame('rented', $this->fortuner()->getAvailabilityStatus());
        $this->payRentalBalance($booking);

        // check-in: the odometer cannot go backwards
        $this->deskAction($booking, 'checkin', ['odometer' => '11999', 'fuel_percent' => '60', 'body_condition' => 'fair', 'notes' => '', 'damage_notes' => '', 'extra_charges' => '1800']);
        self::assertSame('Return odometer cannot be below the checkout odometer.', $this->flash());
        self::assertSame('active', $this->reload($booking)->getStatus());
        $this->deskAction($booking, 'checkin', ['odometer' => '12450', 'fuel_percent' => '60', 'body_condition' => 'fair', 'notes' => '', 'damage_notes' => 'Scratch on rear bumper', 'extra_charges' => '1800']);
        self::assertSame('Return inspection saved. Review any charges before completion.', $this->flash());
        self::assertSame('returned', $this->reload($booking)->getStatus());
        self::assertSame('available', $this->fortuner()->getAvailabilityStatus());
        $inspections = $this->em()->getRepository(RentalInspection::class)->findBy(['booking' => $booking->getId()], ['id' => 'ASC']);
        self::assertSame([12000, 12450], array_map(static fn (RentalInspection $inspection): int => $inspection->getOdometer(), $inspections));
        self::assertSelectorTextContains('.payment-overview', '₱5,000');

        // the customer waits for the settlement
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertSelectorTextContains('main', 'Return Settlement Pending');

        // settlement: the breakdown must match the return charge
        $this->deskAction($booking, 'settle', ['damage_charges' => '1000', 'fuel_charges' => '300', 'late_charges' => '0', 'other_charges' => '0', 'settlement_notes' => '']);
        self::assertSame('Damage, fuel, late, and other deductions must add up to the return-inspection extra charge of ₱1,800.', $this->flash());
        $this->deskAction($booking, 'settle', ['damage_charges' => '1000', 'fuel_charges' => '300', 'late_charges' => '500', 'other_charges' => '0', 'settlement_notes' => 'Bumper scratch']);
        $this->client->followRedirect();
        $settlement = $this->settlement();
        self::assertSame([5000, 1800, 3200, 0, RentalSettlement::STATUS_REFUND_PENDING], [$settlement->getDepositPaid(), $settlement->getTotalDeductions(), $settlement->getDepositRefundAmount(), $settlement->getOutstandingBalance(), $settlement->getStatus()]);
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        self::assertSame([PaymentRefund::TYPE_SECURITY_DEPOSIT, 3200, PaymentRefund::STATUS_PENDING], [$refund->getRefundType(), $refund->getAmount(), $refund->getStatus()]);
        self::assertSame(1, $this->notifications('Security Deposit Refund Pending'));

        // nothing closes while the deposit refund is open
        $this->client->request('GET', '/admin/rentals?reference='.$reference);
        self::assertSelectorExists('.rental-completion-form button[disabled]');
        $this->deskAction($booking, 'complete');
        self::assertSame('Finish the deposit refund or outstanding return balance before completing this rental.', $this->flash());
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertSelectorTextContains('main', 'Security Deposit Refund Pending');
        self::assertSelectorTextContains('#settlement', '₱3,200');

        // the payments desk pays the deposit back
        $this->client->loginUser($this->admin);
        foreach (['approved', 'processing', 'refunded'] as $status) {
            $this->submit('/admin/payments', sprintf('form[action="/admin/refunds/%d/status"]', $refund->getId()), ['refund_status' => $status]);
            self::assertResponseRedirects('/admin/payments#refund-history', 303);
        }
        $refund = $this->em()->getRepository(PaymentRefund::class)->find($refund->getId());
        self::assertSame(PaymentRefund::STATUS_REFUNDED, $refund->getStatus());
        self::assertStringStartsWith('RF-', $refund->getReferenceNumber());
        self::assertSame(RentalSettlement::STATUS_SETTLED, $this->settlement()->getStatus());
        self::assertSame(1, $this->notifications('Security Deposit Refunded'));

        // completion opens the review
        $this->deskAction($booking, 'complete', ['admin_notes' => 'All clear']);
        self::assertSame('Rental completed. The customer can now leave a verified review.', $this->flash());
        $booking = $this->reload($booking);
        self::assertSame('completed', $booking->getStatus());
        self::assertNotNull($booking->getCompletedAt());
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertSelectorTextContains('main', 'Rate Your Rental');
        $this->client->request('GET', '/account/rate-trip');
        self::assertSelectorExists(sprintf('.rating-form input[name="reference"][value="%s"], .rating-form option[value="%s"]', $reference, $reference));
    }

    public function testReturnChargesBeyondTheDepositArePaidBeforeCompletion(): void
    {
        $booking = $this->confirmedBooking();
        $this->submit('/admin/bookings', '#preparation-queue form');
        $this->deskAction($booking, 'checkout', ['odometer' => '500', 'fuel_percent' => '100', 'body_condition' => 'good']);
        $this->payRentalBalance($booking);
        $this->deskAction($booking, 'checkin', ['odometer' => '900', 'fuel_percent' => '20', 'body_condition' => 'damaged', 'damage_notes' => 'Cracked windshield', 'extra_charges' => '6500']);
        $this->deskAction($booking, 'settle', ['damage_charges' => '6000', 'fuel_charges' => '500', 'late_charges' => '0', 'other_charges' => '0']);
        $settlement = $this->settlement();
        self::assertSame([0, 1500, RentalSettlement::STATUS_BALANCE_DUE], [$settlement->getDepositRefundAmount(), $settlement->getOutstandingBalance(), $settlement->getStatus()]);
        self::assertSame(0, $this->em()->getRepository(PaymentRefund::class)->count([]), 'the whole deposit went to the charges');

        // the customer is asked for the ₱1,500 and pays it
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Outstanding Return Balance');
        $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        self::assertSelectorExists('#paymentType option[value="extra_charge"]');
        $this->pay($booking, 'extra_charge', 1500);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Return Payment Verification');

        $this->verifyPayments();
        self::assertSame(RentalSettlement::STATUS_SETTLED, $this->settlement()->getStatus());
        $this->deskAction($booking, 'complete');
        self::assertSame('completed', $this->reload($booking)->getStatus());
    }

    public function testCheckInClosesOpenAdjustmentRequests(): void
    {
        $booking = $this->confirmedBooking();
        $this->submit('/admin/bookings', '#preparation-queue form');
        $this->deskAction($booking, 'checkout', ['odometer' => '500', 'fuel_percent' => '100', 'body_condition' => 'good']);
        $booking = $this->reload($booking);
        $customer = $this->em()->find(User::class, $this->customer->getId());
        $this->em()->persist(new RentalAdjustmentRequest($booking, $customer, RentalAdjustmentRequest::TYPE_EARLY_RETURN, $booking->getReturnAt()->modify('-1 day'), 0, 'Flight moved'));
        $this->em()->persist(new RentalAdjustmentRequest($booking, $customer, RentalAdjustmentRequest::TYPE_EXTENSION, $booking->getReturnAt()->modify('+1 day'), 6200, 'One more day'));
        $this->em()->flush();

        $this->deskAction($booking, 'checkin', ['odometer' => '700', 'fuel_percent' => '90', 'body_condition' => 'good', 'extra_charges' => '0']);

        $this->em()->clear();
        $statuses = [];
        foreach ($this->em()->getRepository(RentalAdjustmentRequest::class)->findBy(['booking' => $booking->getId()]) as $adjustment) {
            $statuses[$adjustment->getRequestType()] = $adjustment->getStatus();
        }
        ksort($statuses);
        // the vehicle is back before the early return was reviewed, so it is applied; the extension no longer applies
        self::assertSame([RentalAdjustmentRequest::TYPE_EARLY_RETURN => 'activated', RentalAdjustmentRequest::TYPE_EXTENSION => 'cancelled'], $statuses);
    }

    public function testDeskActionsFollowTheBookingStatus(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];

        // a pending booking is not on the desk and cannot be marked ready
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/bookings');
        self::assertSelectorTextNotContains('#preparation-queue', $booking->getReference());
        $this->client->request('POST', '/admin/bookings/'.$booking->getId().'/ready', ['_token' => 'forged']);
        self::assertSame('Your session expired. Refresh the page and try again.', $this->flash());
        $this->client->request('GET', '/admin/rentals?reference='.$booking->getReference());
        self::assertSelectorNotExists('#rental-desk');
        $this->client->request('POST', '/admin/rentals/'.$booking->getReference().'/checkout', ['_token' => 'forged', 'odometer' => '1']);
        self::assertSame('Your session expired. Refresh the page and try again.', $this->flash());
        self::assertSame('pending', $this->reload($booking)->getStatus());

        // a vehicle in maintenance cannot be marked ready
        $this->clearBookings();
        $this->clearCustomerRecords();
        $booking = $this->confirmedBooking();
        $this->em()->createQuery('UPDATE '.Vehicle::class.' v SET v.availabilityStatus = :status WHERE v.slug = :slug')->execute(['status' => 'maintenance', 'slug' => 'toyota-fortuner']);
        $this->submit('/admin/bookings', '#preparation-queue form');
        self::assertSame('The vehicle is not operationally available to mark ready.', $this->flash());
        self::assertSame('confirmed', $this->reload($booking)->getStatus());
    }

    /**
     * The ₱18,600 rental total for three days, paid in cash and verified.
     */
    private function payRentalBalance(Booking $booking): void
    {
        $this->pay($booking, 'balance', 18600);
        $this->verifyPayments();
    }

    private function settlement(): RentalSettlement
    {
        $this->em()->clear();

        $booking = $this->em()->createQuery('SELECT b FROM '.Booking::class.' b WHERE b.settlement.finalizedAt IS NOT NULL')
            ->setMaxResults(1)
            ->getOneOrNullResult();
        self::assertInstanceOf(Booking::class, $booking, 'a booking has a finalized settlement');

        return $booking->getSettlement();
    }

    private function notifications(string $title): int
    {
        return $this->em()->getRepository(Notification::class)->count(['user' => $this->customer->getId(), 'title' => $title]);
    }
}
