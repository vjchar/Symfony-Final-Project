<?php

namespace App\Tests\Controller\Admin;

use App\Entity\BookingModificationRequest;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\User;
use App\Service\Booking\BookingModificationManager;
use App\Service\Payment\PaymentLedger;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * v15 payment refunds: manual corrections from Admin Payments, and the
 * refunds an approved cancellation or a cheaper modification creates, with
 * what the customer sees on the booking, payments and invoice pages.
 */
final class RefundTest extends WebTestCase
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

    public function testACorrectionRefundIsCheckedAndProcessed(): void
    {
        $booking = $this->confirmedBooking();
        $deposit = $this->em()->getRepository(Payment::class)->findOneBy(['paymentType' => Payment::TYPE_DEPOSIT]);
        $form = sprintf('form[action="/admin/payments/%d/refund"]', $deposit->getId());

        $this->submit('/admin/payments', $form, ['refund_amount' => '6000', 'refund_reason' => 'Overpayment']);
        self::assertSame('Refund amount cannot exceed ₱5,000.', $this->flash());
        $this->submit('/admin/payments', $form, ['refund_amount' => '1000', 'refund_reason' => 'no']);
        self::assertSame('Provide a clear reason for the payment refund.', $this->flash());
        $this->submit('/admin/payments', $form, ['refund_amount' => '1000', 'refund_reason' => 'Duplicate charge', 'refund_notes' => 'Cashier error']);
        self::assertSame('Payment refund created and added to Refund Management.', $this->flash());
        self::assertSelectorTextContains('#refund-history', 'Remaining refundable on payment: ₱4,000');
        $this->submit('/admin/payments', $form, ['refund_amount' => '1000', 'refund_reason' => 'Duplicate charge']);
        self::assertSame('An identical unresolved payment refund already exists.', $this->flash());

        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        self::assertSame([PaymentRefund::TYPE_PAYMENT_CORRECTION, 1000, 'Cashier error'], [$refund->getRefundType(), $refund->getAmount(), $refund->getAdminNote()]);
        self::assertSame(1, $this->notifications('Payment Refund Pending'));

        // filters
        $this->client->request('GET', '/admin/payments?refund_type=security_deposit');
        self::assertSelectorTextContains('#refund-history', 'No refunds match this filter.');
        $this->client->request('GET', '/admin/payments?refund_q='.$booking->getReference());
        self::assertSelectorTextContains('#refund-history', 'Payment Refund');

        // a refund cannot skip a step
        $status = sprintf('form[action="/admin/refunds/%d/status"]', $refund->getId());
        $this->submit('/admin/payments', $status, ['refund_status' => 'refunded']);
        self::assertSame('That refund status transition is not allowed.', $this->flash());
        foreach (['approved', 'processing'] as $next) {
            $this->submit('/admin/payments', $status, ['refund_status' => $next]);
        }
        $this->submit('/admin/payments', $status, ['refund_status' => 'refunded', 'refund_reference' => 'GCASH-7781']);
        self::assertSame('Refund status updated.', $this->flash());

        $this->em()->clear();
        $refund = $this->em()->getRepository(PaymentRefund::class)->find($refund->getId());
        self::assertSame([PaymentRefund::STATUS_REFUNDED, 'GCASH-7781', 'Vina Admin'], [$refund->getStatus(), $refund->getReferenceNumber(), $refund->getApprovedBy()?->getName()]);
        self::assertSame(1, $this->notifications('Payment Refund Processed'));

        // the corrected deposit is short again, so the booking goes back to payment
        $booking = $this->reload($booking);
        self::assertSame('pending', $booking->getStatus());
        self::assertSame(1000, static::getContainer()->get(PaymentLedger::class)->summary($booking)->depositDue);

        // the customer sees it on the invoice and the payments page
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference().'/invoice');
        self::assertSelectorTextContains('.invoice-table', 'Processed refunds');
        self::assertSelectorTextContains('.invoice-refunds', 'GCASH-7781');
        $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        self::assertSelectorTextContains('#refund-history', 'Payment Refund');
        self::assertSelectorTextContains('#refund-history', 'Refunded');
    }

    public function testAnApprovedCancellationCreatesTheRefund(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->pay($booking, 'deposit', 5000);
        $this->verifyPayments();

        // the customer asks to cancel more than 24 hours before pickup
        $this->client->loginUser($this->customer);
        $this->submit('/bookings/'.$booking->getReference().'/cancel', 'form[action$="/cancel"]', ['reason' => 'Trip postponed']);
        self::assertResponseRedirects();
        $this->client->loginUser($this->admin);
        $this->submit('/admin/bookings', '#cancellation-queue form', ['decision' => 'approved', 'admin_note' => 'OK']);

        self::assertSame('cancelled', $this->reload($booking)->getStatus());
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        self::assertSame([PaymentRefund::TYPE_CANCELLATION, 5000, PaymentRefund::STATUS_PENDING], [$refund->getRefundType(), $refund->getAmount(), $refund->getStatus()]);
        self::assertNotNull($refund->getCancellationRequest());
        self::assertSame(1, $this->notifications('Cancellation Approved'));
        self::assertSame(1, $this->notifications('Cancellation Refund Pending'));

        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('main', 'Refund Pending');
        self::assertSelectorTextContains('#refunds', 'Cancellation Refund');
        self::assertSelectorTextContains('#refunds', '₱5,000');
    }

    public function testACheaperModificationCreditsTheRentalPayment(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->pay($booking, 'deposit', 5000);
        $this->pay($booking, 'balance', 18600);
        $this->verifyPayments();

        // one day shorter: ₱12,400 instead of ₱18,600
        $this->client->loginUser($this->customer);
        $this->submit('/bookings/'.$booking->getReference().'/modify', 'form.operation-card', [
            'vehicle' => 'toyota-fortuner',
            'pickup' => $this->day(2),
            'pickup_time' => '09:00',
            'return' => $this->day(4),
            'return_time' => '09:00',
            'pickup_method' => 'Branch pickup',
            'location' => 'Dumaguete City Branch',
            'delivery_address' => '',
            'promo' => '',
            'special_requests' => '',
            'reason' => 'Shorter trip',
            'action' => 'submit_request',
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        $modification = $this->em()->getRepository(BookingModificationRequest::class)->findOneBy([]);
        static::getContainer()->get(BookingModificationManager::class)->review($modification, BookingModificationRequest::STATUS_APPROVED, $this->em()->find(User::class, $this->admin->getId()));

        $this->em()->clear();
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        self::assertSame([PaymentRefund::TYPE_BOOKING_MODIFICATION, 6200, Payment::TYPE_BALANCE], [$refund->getRefundType(), $refund->getAmount(), $refund->getPayment()->getPaymentType()]);
        self::assertSame(12400, $this->reload($booking)->getTotal());

        // once paid out, the rental is exactly covered
        $this->client->loginUser($this->admin);
        foreach (['approved', 'processing', 'refunded'] as $status) {
            $this->submit('/admin/payments', sprintf('form[action="/admin/refunds/%d/status"]', $refund->getId()), ['refund_status' => $status]);
        }
        $summary = static::getContainer()->get(PaymentLedger::class)->summary($this->reload($booking));
        self::assertSame([0, 12400 + 5000, 23600], [$summary->rentalDue, $summary->paidTotal, $summary->grossPaid]);
    }

    public function testReportsCountRefundsAndSettlements(): void
    {
        $booking = $this->confirmedBooking();
        $deposit = $this->em()->getRepository(Payment::class)->findOneBy(['paymentType' => Payment::TYPE_DEPOSIT]);
        $this->submit('/admin/payments', sprintf('form[action="/admin/payments/%d/refund"]', $deposit->getId()), ['refund_amount' => '1000', 'refund_reason' => 'Duplicate charge']);
        $refund = $this->em()->getRepository(PaymentRefund::class)->findOneBy([]);
        foreach (['approved', 'processing', 'refunded'] as $status) {
            $this->submit('/admin/payments', sprintf('form[action="/admin/refunds/%d/status"]', $refund->getId()), ['refund_status' => $status]);
        }

        $range = 'from='.date('Y-m-d').'&to='.date('Y-m-d');
        $this->client->request('GET', '/admin/reports?'.$range);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-stats', 'Processed refunds');
        self::assertSelectorTextContains('.admin-stats', '₱4,000');
        self::assertSelectorTextContains('.admin-table', $booking->getReference());

        $this->client->request('GET', '/admin/reports?'.$range.'&export=csv');
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('"Verified Payments",Refunds,"Net Verified Payments"', $csv);
        self::assertStringContainsString(',5000,1000,4000', $csv);
    }

    private function notifications(string $title): int
    {
        return $this->em()->getRepository(Notification::class)->count(['user' => $this->customer->getId(), 'title' => $title]);
    }
}
