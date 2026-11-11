<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\PaymentRefund;
use App\Entity\RentalAdjustmentRequest;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * rental-adjustment.php early returns, driven through the customer's adjust
 * page and the admin rentals queue. The rental is the three-day ₱18,600
 * Fortuner booking (₱6,200 a day), picked up in two days at 09:00.
 */
final class RentalAdjustmentTest extends WebTestCase
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

    public function testAnApprovedEarlyReturnMovesTheReturnAndRefundsThePaidUnusedDays(): void
    {
        $booking = $this->activeRental();
        $this->payRentalBalance($booking);
        $reference = $booking->getReference();

        // the customer asks to bring it back after one day
        $this->requestEarlyReturn($booking, $this->day(3), '09:00', 'Flight moved');
        self::assertResponseRedirects('/bookings/'.$reference, 303);
        $adjustment = $this->adjustment();
        self::assertSame([RentalAdjustmentRequest::STATUS_PENDING, -12400], [$adjustment->getStatus(), $adjustment->getPriceDifference()]);
        self::assertSame(1, $this->notifications($this->admin, 'Early return requested'));
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertSelectorTextContains('main', 'Early Return Request Pending');

        // the rental desk approves it
        $this->review($adjustment, 'approve', 'See you at the branch');
        $booking = $this->reload($booking);
        self::assertSame($this->day(3).' 09:00', $booking->getReturnAt()->format('Y-m-d H:i'));
        self::assertSame($this->day(5).' 09:00', $booking->getOriginalReturnAt()?->format('Y-m-d H:i'));
        self::assertSame([6200, 6200], [$booking->getSubtotal(), $booking->getTotal()]);
        self::assertSame(RentalAdjustmentRequest::STATUS_ACTIVATED, $this->adjustment()->getStatus());

        // the ₱12,400 paid for the two unused days goes back from the balance payment
        $refunds = $this->em()->getRepository(PaymentRefund::class)->findAll();
        self::assertCount(1, $refunds);
        self::assertSame([PaymentRefund::TYPE_EARLY_RETURN, 12400, PaymentRefund::STATUS_PENDING, 'balance'], [$refunds[0]->getRefundType(), $refunds[0]->getAmount(), $refunds[0]->getStatus(), $refunds[0]->getPayment()->getPaymentType()]);
        self::assertSame(1, $this->notifications($this->customer, 'Early return approved'));
        self::assertSame(1, $this->notifications($this->customer, 'Early Return Refund Pending'));

        // the freed days can be booked by someone else
        $bookings = static::getContainer()->get(BookingRepository::class);
        self::assertTrue($bookings->isVehicleAvailable($booking->getVehicle(), new \DateTimeImmutable($this->day(3).' 12:00'), new \DateTimeImmutable($this->day(5).' 09:00')));

        // the customer sees the note and is free to ask for another change
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference.'/adjust');
        self::assertSelectorTextContains('.booking-management-panel', 'See you at the branch');
        self::assertSelectorTextContains('.booking-management-panel', '−₱12,400');
        self::assertSelectorExists('input[name="action"][value="submit_early_return"]');
    }

    public function testARejectionIsExplainedAndAnUnpaidRentalIsRepricedWithoutARefund(): void
    {
        $booking = $this->activeRental();
        $reference = $booking->getReference();

        $this->requestEarlyReturn($booking, $this->day(4), '09:00');
        $this->review($this->adjustment(), 'reject', 'The branch is closed that day');
        self::assertSame(RentalAdjustmentRequest::STATUS_REJECTED, $this->adjustment()->getStatus());
        self::assertSame($this->day(5), $this->reload($booking)->getReturnAt()->format('Y-m-d'));
        self::assertSame(1, $this->notifications($this->customer, 'Early return not approved'));
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$reference.'/adjust');
        self::assertSelectorTextContains('.booking-management-panel', 'The branch is closed that day');

        // nothing was paid towards the rental yet, so the lower total is all that changes
        $this->requestEarlyReturn($booking, $this->day(4), '09:00');
        $this->review($this->adjustment(), 'approve');
        $booking = $this->reload($booking);
        self::assertSame(12400, $booking->getTotal());
        self::assertSame(0, $this->em()->getRepository(PaymentRefund::class)->count([]));
        $approval = $this->em()->getRepository(Notification::class)->findOneBy(['user' => $this->customer->getId(), 'title' => 'Early return approved']);
        self::assertStringContainsString('Your rental total went down by ₱6,200.', $approval->getMessage());
    }

    public function testTheRequestIsValidatedAndCanBeWithdrawnWhilePending(): void
    {
        $booking = $this->activeRental();
        $reference = $booking->getReference();

        $this->requestEarlyReturn($booking, 'not-a-date', '09:00');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid return date and time.');
        $this->requestEarlyReturn($booking, $this->day(6), '09:00');
        self::assertSelectorTextContains('.alert-danger', 'Choose a return time after vehicle release and before the current scheduled return.');
        $this->requestEarlyReturn($booking, $this->day(3), '09:00', token: 'forged');
        self::assertSelectorTextContains('.alert-danger', 'Your session expired. Refresh the page and try again.');
        self::assertSame(0, $this->em()->getRepository(RentalAdjustmentRequest::class)->count([]));

        // one open request at a time, and a pending one can be withdrawn
        $this->requestEarlyReturn($booking, $this->day(3), '09:00');
        self::assertResponseRedirects('/bookings/'.$reference, 303);
        $crawler = $this->client->request('GET', '/bookings/'.$reference.'/adjust');
        self::assertSelectorNotExists('input[name="action"][value="submit_early_return"]');
        $withdraw = $crawler->filter('form[action$="/withdraw"]');
        $this->client->request('POST', (string) $withdraw->attr('action'), ['_token' => (string) $withdraw->filter('input[name="_token"]')->attr('value')]);
        self::assertSame(RentalAdjustmentRequest::STATUS_CANCELLED, $this->adjustment()->getStatus());
        self::assertSame($this->day(5), $this->reload($booking)->getReturnAt()->format('Y-m-d'));
    }

    public function testAPendingEarlyReturnIsAppliedWhenTheVehicleComesBackBeforeReview(): void
    {
        $booking = $this->activeRental();
        $this->payRentalBalance($booking);
        $this->requestEarlyReturn($booking, $this->day(3), '09:00');

        // the desk checks the vehicle in without reviewing the request first
        $this->deskAction($booking, 'checkin', ['odometer' => '600', 'fuel_percent' => '100', 'body_condition' => 'good', 'extra_charges' => '0']);

        $adjustment = $this->adjustment();
        self::assertSame(RentalAdjustmentRequest::STATUS_ACTIVATED, $adjustment->getStatus());
        $booking = $this->reload($booking);
        self::assertSame('returned', $booking->getStatus());
        // back before pickup: one billed day, the other two refunded
        self::assertSame(6200, $booking->getTotal());
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy(['refundType' => PaymentRefund::TYPE_EARLY_RETURN]);
        self::assertSame(12400, $refund?->getAmount());
    }

    /**
     * Confirmed, prepared and checked out from the admin pages.
     */
    private function activeRental(): Booking
    {
        $booking = $this->confirmedBooking();
        $this->submit('/admin/bookings', '#preparation-queue form');
        $this->deskAction($booking, 'checkout', ['odometer' => '500', 'fuel_percent' => '100', 'body_condition' => 'good']);
        self::assertSame('active', $this->reload($booking)->getStatus());

        return $this->reload($booking);
    }

    private function payRentalBalance(Booking $booking): void
    {
        $this->pay($booking, 'balance', 18600);
        $this->verifyPayments();
    }

    private function requestEarlyReturn(Booking $booking, string $date, string $time, string $reason = '', ?string $token = null): void
    {
        $this->client->loginUser($this->customer);
        $url = '/bookings/'.$booking->getReference().'/adjust';
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->filter('form:has(input[name="action"][value="submit_early_return"])');
        self::assertCount(1, $form, 'the early-return form is offered');
        $this->client->request('POST', $url, [
            '_token' => $token ?? (string) $form->filter('input[name="_token"]')->attr('value'),
            'action' => 'submit_early_return',
            'requested_date' => $date,
            'requested_time' => $time,
            'reason' => $reason,
        ]);
    }

    private function review(RentalAdjustmentRequest $adjustment, string $decision, string $note = ''): void
    {
        $this->client->loginUser($this->admin);
        $this->submit('/admin/rentals', sprintf('form[action="/admin/rentals/adjustments/%d/review"]', $adjustment->getId()), ['decision' => $decision, 'admin_note' => $note]);
        self::assertSame('Rental adjustment request reviewed.', $this->flash());
    }

    private function adjustment(): RentalAdjustmentRequest
    {
        $this->em()->clear();

        return $this->em()->getRepository(RentalAdjustmentRequest::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function notifications(User $user, string $title): int
    {
        return $this->em()->getRepository(Notification::class)->count(['user' => $user->getId(), 'title' => $title]);
    }
}
