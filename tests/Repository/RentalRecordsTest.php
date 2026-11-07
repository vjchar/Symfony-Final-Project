<?php

namespace App\Tests\Repository;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\RentalInspection;
use App\Entity\RentalSettlement;
use App\Entity\User;
use App\Repository\PaymentRefundRepository;
use App\Repository\RentalInspectionRepository;
use App\Repository\RentalSettlementRepository;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The v15 rental_inspections, rental_settlements and payment_refunds records,
 * saved and read back through their repositories.
 */
final class RentalRecordsTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;

    private KernelBrowser $client;
    private User $admin;
    private Booking $booking;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->client->loginUser($this->createUser('juan@test.vj', name: 'Juan Dela Cruz'));
        $this->submitBooking($this->client);
        $this->booking = $this->bookings()[0];
        $this->admin = $this->em()->find(User::class, $this->admin->getId());
    }

    protected function tearDown(): void
    {
        // a unique-constraint failure closes the entity manager
        if (!$this->em()->isOpen()) {
            static::getContainer()->get('doctrine')->resetManager();
        }
        // inspections, settlements, payments and refunds go with the bookings (ON DELETE CASCADE)
        $this->clearBookings();
        parent::tearDown();
    }

    public function testInspectionsAreKeptPerBookingAndType(): void
    {
        $checkout = new RentalInspection($this->booking, RentalInspection::TYPE_CHECKOUT, $this->admin);
        $checkout->record(12000, 100, 'excellent', ' Clean ', '', 2500, $this->admin);
        $checkin = new RentalInspection($this->booking, RentalInspection::TYPE_CHECKIN, $this->admin);
        $checkin->record(12450, 60, 'fair', '', 'Scratch on rear bumper', 1800, $this->admin);
        $this->save($checkout, $checkin);

        $inspections = $this->repository(RentalInspection::class, RentalInspectionRepository::class)->findForBooking($this->reloadedBooking());

        self::assertSame([RentalInspection::TYPE_CHECKOUT, RentalInspection::TYPE_CHECKIN], array_keys($inspections));
        self::assertSame(0, $inspections['checkout']->getExtraCharges(), 'only a return inspection carries charges');
        self::assertSame('Clean', $inspections['checkout']->getNotes());
        self::assertSame(12450, $inspections['checkin']->getOdometer());
        self::assertSame(1800, $inspections['checkin']->getExtraCharges());
        self::assertSame('Scratch on rear bumper', $inspections['checkin']->getDamageNotes());
    }

    public function testASecondInspectionOfTheSameTypeIsRefused(): void
    {
        $this->save(new RentalInspection($this->booking, RentalInspection::TYPE_CHECKOUT, $this->admin));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(new RentalInspection($this->reloadedBooking(), RentalInspection::TYPE_CHECKOUT, $this->reloadedAdmin()));
    }

    public function testInspectionValuesAreRangeChecked(): void
    {
        $inspection = new RentalInspection($this->booking, RentalInspection::TYPE_CHECKIN, $this->admin);

        foreach ([[-1, 50, 'good'], [100, 101, 'good'], [100, 50, 'scratched']] as [$odometer, $fuel, $condition]) {
            try {
                $inspection->record($odometer, $fuel, $condition, '', '', 0, $this->admin);
                self::fail(sprintf('%d km, %d%%, %s should be refused', $odometer, $fuel, $condition));
            } catch (\InvalidArgumentException $error) {
                self::assertSame(RentalInspection::INVALID, $error->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new RentalInspection($this->booking, 'midway', $this->admin);
    }

    public function testSettlementWorksOutTheDepositRefundOrTheBalanceDue(): void
    {
        $refund = new RentalSettlement($this->booking, 5000, 1000, 300, 200, 0, $this->admin, 'Minor scratch');
        self::assertSame(1500, $refund->getTotalDeductions());
        self::assertSame(3500, $refund->getDepositRefundAmount());
        self::assertSame(0, $refund->getOutstandingBalance());
        self::assertSame(RentalSettlement::STATUS_REFUND_PENDING, $refund->getStatus());

        $balance = new RentalSettlement($this->booking, 5000, 6000, 0, 500, 0, $this->admin);
        self::assertSame(0, $balance->getDepositRefundAmount());
        self::assertSame(1500, $balance->getOutstandingBalance());
        self::assertSame(RentalSettlement::STATUS_BALANCE_DUE, $balance->getStatus());

        self::assertSame(RentalSettlement::STATUS_SETTLED, (new RentalSettlement($this->booking, 2000, 2000, 0, 0, 0, $this->admin))->getStatus());

        // v15 refresh_rental_settlement_status()
        $balance->refreshStatus(0, 0, false);
        self::assertTrue($balance->isSettled(), 'paid return charge settles it');
        $refund->refreshStatus(0, 3000, false);
        self::assertSame(RentalSettlement::STATUS_REFUND_PENDING, $refund->getStatus(), 'only part of the deposit is back');
        $refund->refreshStatus(0, 3500, false);
        self::assertTrue($refund->isSettled());
    }

    public function testOneSettlementPerBookingIsSavedAndFound(): void
    {
        $this->save(new RentalSettlement($this->booking, 5000, 0, 0, 0, 1200, $this->admin));

        $settlement = $this->repository(RentalSettlement::class, RentalSettlementRepository::class)->findForBooking($this->reloadedBooking());
        self::assertNotNull($settlement);
        self::assertSame(3800, $settlement->getDepositRefundAmount());
        self::assertSame('Vina Admin', $settlement->getFinalizedBy()->getName());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(new RentalSettlement($this->reloadedBooking(), 5000, 0, 0, 0, 0, $this->reloadedAdmin()));
    }

    public function testRefundsMoveThroughTheV15Statuses(): void
    {
        $refund = new PaymentRefund($this->verifiedPayment(5000), PaymentRefund::TYPE_PAYMENT_CORRECTION, 1000, 'Double charge');
        $this->save($refund);
        self::assertSame(PaymentRefund::STATUS_PENDING, $refund->getStatus());
        self::assertSame($this->booking->getId(), $refund->getBooking()->getId());

        $refund->transitionTo(PaymentRefund::STATUS_APPROVED, $this->admin, 'OK');
        $refund->transitionTo(PaymentRefund::STATUS_PROCESSING, $this->admin);
        $refund->transitionTo(PaymentRefund::STATUS_REFUNDED, $this->admin);

        self::assertTrue($refund->isRefunded());
        self::assertNotNull($refund->getApprovedAt());
        self::assertNotNull($refund->getProcessedAt());
        self::assertSame(sprintf('RF-%s-%06d', date('Ymd'), $refund->getId()), $refund->getReferenceNumber());
        self::assertSame([], $refund->getNextStatuses());

        $this->expectException(\DomainException::class);
        $refund->transitionTo(PaymentRefund::STATUS_PROCESSING, $this->admin);
    }

    public function testASecurityDepositRefundCannotBeRejected(): void
    {
        $refund = new PaymentRefund($this->verifiedPayment(5000), PaymentRefund::TYPE_SECURITY_DEPOSIT, 3500, 'Unused deposit');
        self::assertSame([PaymentRefund::STATUS_APPROVED], $refund->getNextStatuses());

        $this->expectExceptionMessage('cannot be rejected');
        $refund->transitionTo(PaymentRefund::STATUS_REJECTED, $this->admin);
    }

    public function testOnlyAVerifiedPaymentCanBeRefunded(): void
    {
        $pending = new Payment($this->booking, $this->booking->getUser(), Payment::TYPE_DEPOSIT, Payment::METHOD_CASH, 5000, '', '');

        $this->expectExceptionMessage('Only a verified payment can be refunded.');
        new PaymentRefund($pending, PaymentRefund::TYPE_PAYMENT_CORRECTION, 100, 'Test');
    }

    public function testRefundTotalsCountOpenAndPaidRefunds(): void
    {
        $payment = $this->verifiedPayment(5000);
        $settlement = new RentalSettlement($this->booking, 5000, 1500, 0, 0, 0, $this->admin);
        $open = new PaymentRefund($payment, PaymentRefund::TYPE_SECURITY_DEPOSIT, 2000, 'Deposit', rentalSettlement: $settlement);
        $paid = new PaymentRefund($payment, PaymentRefund::TYPE_SECURITY_DEPOSIT, 1500, 'Deposit', rentalSettlement: $settlement);
        $rejected = new PaymentRefund($payment, PaymentRefund::TYPE_PAYMENT_CORRECTION, 900, 'Duplicate');
        $this->save($settlement, $open, $paid, $rejected);
        foreach ([PaymentRefund::STATUS_APPROVED, PaymentRefund::STATUS_PROCESSING, PaymentRefund::STATUS_REFUNDED] as $status) {
            $paid->transitionTo($status, $this->admin);
        }
        $rejected->transitionTo(PaymentRefund::STATUS_REJECTED, $this->admin);
        $this->save();

        $refunds = $this->repository(PaymentRefund::class, PaymentRefundRepository::class);
        self::assertSame(3500, $refunds->committedAmountForPayment($payment));
        self::assertSame(1500, $refunds->committedAmountForPayment($payment, except: $open));
        self::assertSame(1500, $refunds->refundableBalance($payment), 'rejected refunds give the money back to the balance');
        self::assertSame(['pending' => 2000, 'refunded' => 1500], $refunds->depositTotalsForSettlement($settlement));
        self::assertCount(3, $refunds->findForBooking($this->booking));
    }

    private function verifiedPayment(int $amount): Payment
    {
        $payment = new Payment($this->booking, $this->booking->getUser(), Payment::TYPE_DEPOSIT, Payment::METHOD_CASH, $amount, '', '');
        $payment->review(Payment::STATUS_PAID, 'Cash received', $this->admin);
        $this->save($payment);

        return $payment;
    }

    private function save(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $repositoryClass
     *
     * @return T
     */
    private function repository(string $entityClass, string $repositoryClass): object
    {
        $repository = $this->em()->getRepository($entityClass);
        self::assertInstanceOf($repositoryClass, $repository);

        return $repository;
    }

    private function reloadedBooking(): Booking
    {
        $this->em()->clear();

        return $this->em()->find(Booking::class, $this->booking->getId());
    }

    private function reloadedAdmin(): User
    {
        return $this->em()->find(User::class, $this->admin->getId());
    }
}
