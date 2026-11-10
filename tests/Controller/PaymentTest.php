<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\User;
use App\Service\Payment\PaymentManager;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PaymentTest extends WebTestCase
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
        $this->loginAsAdmin($this->client);
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
        $this->client->loginUser($this->customer);
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testCashDepositIsRecordedAndLeadsToDocuments(): void
    {
        $booking = $this->book();
        $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.journey-next h2', 'Complete Payment');
        self::assertSelectorTextContains('.payment-overview', '₱5,000');
        self::assertSelectorExists('#paymentType option[value="deposit"]');
        self::assertSelectorTextContains('[data-payment-details="gcash"]', '0994 894 5174');

        $this->pay($booking, ['method' => 'cash', 'amount' => '5000']);

        self::assertResponseRedirects('/account/documents?reference='.$booking->getReference().'&payment_submitted=1', 303);
        $payment = $this->payments()[0];
        self::assertSame([Payment::TYPE_DEPOSIT, 'cash', 5000, Payment::STATUS_PENDING], [$payment->getPaymentType(), $payment->getMethod(), $payment->getAmount(), $payment->getStatus()]);

        $customerNotice = $this->em()->getRepository(Notification::class)->findOneBy(['user' => $this->customer]);
        self::assertSame('Payment Submitted — Upload Documents', $customerNotice->getTitle());
        $activeAdmins = $this->em()->getRepository(User::class)->count(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
        self::assertSame($activeAdmins, $this->em()->getRepository(Notification::class)->count(['title' => 'Payment awaiting verification']), 'every active administrator is told');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Payment submitted. Your next unfinished step is to upload the required documents.');
        self::assertSelectorTextContains('.journey-next h2', 'Upload Required Documents');

        // still pending, so nothing counts as paid yet
        $this->client->request('GET', '/bookings/'.$booking->getReference());
        self::assertSelectorTextContains('#requirements', '₱5,000 due');
        self::assertSelectorTextContains('.notification-link span', '1');
    }

    public function testElectronicPaymentsNeedAReferenceAndProof(): void
    {
        $booking = $this->book();

        $this->pay($booking, ['method' => 'gcash', 'amount' => '5000', 'transaction_reference' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Enter the transaction/reference number from your payment.');

        $this->pay($booking, ['method' => 'gcash', 'amount' => '5000', 'transaction_reference' => 'GC-778812']);
        self::assertSelectorTextContains('.alert-danger', 'Upload your payment proof before submitting the payment.');
        self::assertCount(0, $this->payments());

        $this->pay($booking, ['method' => 'gcash', 'amount' => '5000', 'transaction_reference' => 'GC-778812'], $this->png());
        self::assertResponseRedirects();
        $payment = $this->payments()[0];
        self::assertSame('GC-778812', $payment->getTransactionReference());
        self::assertMatchesRegularExpression('/^payment-'.$booking->getReference().'-[0-9a-f]{16}\.png$/', $payment->getProofFilename());

        // the proof is private: its owner can open it, another customer cannot
        $this->client->request('GET', '/files/payment/'.$payment->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $this->client->loginUser($this->createUser('maria@test.vj'));
        $this->client->request('GET', '/files/payment/'.$payment->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAmountsAreCheckedAgainstWhatIsDue(): void
    {
        $booking = $this->book();

        $this->pay($booking, ['method' => 'cash', 'amount' => '5001']);
        self::assertSelectorTextContains('.alert-danger', 'Enter an amount no greater than the current amount due.');
        $this->pay($booking, ['method' => 'cash', 'amount' => '12.50']);
        self::assertSelectorTextContains('.alert-danger', 'Enter a valid whole-peso amount.');
        $this->pay($booking, ['method' => 'cash', 'amount' => '100', 'payment_type' => 'extension']);
        self::assertSelectorTextContains('.alert-danger', 'That extension is not awaiting payment.');
        self::assertCount(0, $this->payments());
    }

    public function testAnotherCustomersBookingIsNotPayable(): void
    {
        $booking = $this->book();
        $this->client->loginUser($this->createUser('maria@test.vj'));

        $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        self::assertSelectorTextContains('.empty-state h2', 'No payable bookings');
        self::assertSelectorNotExists('#paymentForm');

        $this->client->request('GET', '/bookings/'.$booking->getReference().'/invoice');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Invoice not found');
    }

    public function testInvoiceCountsOnlyVerifiedPayments(): void
    {
        $booking = $this->book();
        $this->pay($booking, ['method' => 'cash', 'amount' => '5000']);

        $this->client->request('GET', '/bookings/'.$booking->getReference().'/invoice');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invoice-header h1', $booking->getReference());
        self::assertSelectorTextContains('.invoice-total', '₱18,600');
        // the pending deposit is not deducted: rental total plus deposit is still due
        self::assertSelectorTextContains('.invoice-balance', '₱23,600');

        $this->em()->createQuery('UPDATE '.Payment::class.' p SET p.status = :paid')->execute(['paid' => Payment::STATUS_PAID]);
        $this->client->request('GET', '/bookings/'.$booking->getReference().'/invoice');
        self::assertSelectorTextContains('.invoice-balance', '₱18,600');
    }

    public function testPendingPaymentsCountAgainstWhatIsDue(): void
    {
        $booking = $this->book();
        $this->pay($booking, ['method' => 'cash', 'amount' => '3000']);
        self::assertCount(1, $this->payments());

        // ₱5,000 deposit with ₱3,000 awaiting verification leaves ₱2,000
        $this->pay($booking, ['method' => 'cash', 'amount' => '3000']);
        self::assertSelectorTextContains('.alert-danger', 'Enter an amount no greater than the current amount due of ₱2,000. Payments awaiting verification are already counted.');
        self::assertCount(1, $this->payments());

        $this->pay($booking, ['method' => 'cash', 'amount' => '2000']);
        self::assertCount(2, $this->payments());
    }

    public function testOverlappingPaymentsCannotBothBeVerified(): void
    {
        $booking = $this->book();
        // two full deposits already waiting (e.g. recorded before this check existed)
        $first = $this->pendingPayment($booking, Payment::TYPE_DEPOSIT, 5000);
        $second = $this->pendingPayment($booking, Payment::TYPE_DEPOSIT, 5000);
        $manager = static::getContainer()->get(PaymentManager::class);
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $manager->review($first, Payment::STATUS_PAID, '', $admin);

        try {
            $manager->review($second, Payment::STATUS_PAID, '', $admin);
            self::fail('A second full deposit was verified.');
        } catch (\DomainException $error) {
            self::assertSame('This payment of ₱5,000 is more than the ₱0 still due for this deposit. Mark it failed and ask the customer to submit the correct amount.', $error->getMessage());
        }
        self::assertSame(Payment::STATUS_PENDING, $this->em()->find(Payment::class, $second->getId())->getStatus());

        // it can still be turned down
        $manager->review($this->em()->find(Payment::class, $second->getId()), Payment::STATUS_FAILED, 'Duplicate', $admin);
        self::assertSame(Payment::STATUS_FAILED, $this->em()->find(Payment::class, $second->getId())->getStatus());
    }

    public function testAReviewedPaymentCannotBeReviewedAgain(): void
    {
        $booking = $this->book();
        $payment = $this->pendingPayment($booking, Payment::TYPE_DEPOSIT, 5000);
        $manager = static::getContainer()->get(PaymentManager::class);
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        $manager->review($payment, Payment::STATUS_PAID, '', $admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This payment was already reviewed.');
        $manager->review($this->em()->find(Payment::class, $payment->getId()), Payment::STATUS_FAILED, '', $admin);
    }

    public function testAdminPageOnlyOffersReviewForPendingPayments(): void
    {
        $booking = $this->book();
        $pending = $this->pendingPayment($booking, Payment::TYPE_DEPOSIT, 2000);
        $verified = $this->pendingPayment($booking, Payment::TYPE_DEPOSIT, 3000);
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        static::getContainer()->get(PaymentManager::class)->review($verified, Payment::STATUS_PAID, '', $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/payments');

        self::assertSelectorExists(sprintf('form[action^="/admin/payments/%d/review"]', $pending->getId()));
        self::assertSelectorNotExists(sprintf('form[action^="/admin/payments/%d/review"]', $verified->getId()));
    }

    private function pendingPayment(Booking $booking, string $type, int $amount): Payment
    {
        $em = $this->em();
        $booking = $em->find(Booking::class, $booking->getId());
        $payment = new Payment($booking, $booking->getUser(), $type, Payment::METHOD_CASH, $amount, '', '');
        $em->persist($payment);
        $em->flush();

        return $payment;
    }

    private function book(): Booking
    {
        $this->submitBooking($this->client);

        return $this->bookings()[0];
    }

    /**
     * @param array<string, string> $fields
     */
    private function pay(Booking $booking, array $fields, ?\Symfony\Component\HttpFoundation\File\UploadedFile $proof = null): void
    {
        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        $token = $crawler->filter('#paymentForm input[name="_token"]')->attr('value');

        $this->client->request('POST', '/account/payments', $fields + [
            '_token' => $token,
            'reference' => $booking->getReference(),
            'payment_type' => 'deposit',
            'transaction_reference' => '',
        ], null === $proof ? [] : ['payment_proof' => $proof]);
    }

    /** @return list<Payment> */
    private function payments(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(Payment::class)->findAll();
    }
}
