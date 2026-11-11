<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use App\Entity\ContactMessage;
use App\Entity\CustomerDocument;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Promo;
use App\Entity\User;
use App\Message\SweepRentals;
use App\Schedule;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\RunsRentals;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;

/**
 * Promotion limits, duplicate payment references, the date search, the
 * booking window, the customer's privacy controls and document retention,
 * the audit log, admin list paging, contact replies and the scheduled sweep.
 */
final class OperationsHardeningTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use RunsRentals;
    use UploadsFiles;

    private const PROMO = 'ONCEONLY';

    private KernelBrowser $client;
    private User $customer;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        $this->rememberVehicleStatus();
        $this->admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $this->customer = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->restoreVehicleStatus();
        parent::tearDown();
    }

    public function testAPromotionUseIsClaimedOnceAndGivenBackWhenTheBookingCloses(): void
    {
        $promo = (new Promo())->setCode(self::PROMO)->setDescription('One use only')->setDiscountValue(10)->setMaxUses(1);
        $this->em()->persist($promo);
        $this->em()->flush();

        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client, ['promo' => self::PROMO]);
        $booking = $this->bookings()[0];
        self::assertSame(1, $this->promoUses());

        // the only use is taken
        $this->submitBooking($this->client, ['promo' => self::PROMO, 'pickup' => $this->day(10), 'return' => $this->day(12)]);
        self::assertSelectorTextContains('main', 'The promotion code is invalid, inactive, expired, or fully used.');
        self::assertCount(1, $this->bookings());

        // rejecting the booking gives the use back
        $this->client->loginUser($this->admin);
        $this->submit('/admin/bookings', sprintf('form[action="/admin/bookings/%d/reject"]', $booking->getId()), ['admin_note' => 'Duplicate booking']);
        self::assertSame(0, $this->promoUses());
    }

    public function testOneTransferReferencePaysForOneSubmissionOnly(): void
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $first = $this->bookings()[0];
        $this->submitBooking($this->client, ['pickup' => $this->day(10), 'return' => $this->day(12)]);
        $second = $this->bookings()[1];

        $this->transfer($first, 'GC-778812');
        self::assertResponseRedirects();
        // the same receipt, typed differently, for another booking
        $this->transfer($second, 'gc-778 812');
        self::assertSelectorTextContains('main', 'That transaction/reference number was already submitted.');
        self::assertSame(1, $this->em()->getRepository(Payment::class)->count([]));
    }

    public function testCustomersCanSearchByDateAndBookAtMostAYearAhead(): void
    {
        $booking = $this->confirmedBooking();
        $fortuner = $booking->getVehicle()->getName();

        $this->client->request('GET', sprintf('/vehicles?from=%s&to=%s', $this->day(3), $this->day(4)));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', $fortuner);
        $this->client->request('GET', sprintf('/vehicles?from=%s&to=%s', $this->day(8), $this->day(9)));
        self::assertSelectorTextContains('main', $fortuner);

        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client, ['pickup' => $this->day(400), 'return' => $this->day(402)]);
        self::assertSelectorTextContains('main', 'Bookings can start up to 12 months ahead.');
    }

    public function testACustomerCanDownloadTheirDataAndCloseTheirAccount(): void
    {
        $booking = $this->confirmedBooking();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/account/privacy/export');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=vj-car-rental-my-data.json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('juan@test.vj', $data['account']['email']);
        self::assertSame($booking->getReference(), $data['bookings'][0]['reference']);
        self::assertSame(5000, $data['bookings'][0]['payments'][0]['amount']);

        // not while the booking is still open
        $this->client->request('GET', '/account/privacy');
        self::assertSelectorTextContains('#close-account', 'Booking '.$booking->getReference().' is still in progress.');

        $this->em()->createQuery('UPDATE '.Booking::class.' b SET b.status = :cancelled WHERE b.id = :id')->execute(['cancelled' => 'cancelled', 'id' => $booking->getId()]);
        $this->client->request('GET', '/account/privacy');
        $this->client->submitForm('Close My Account', ['account_closure[password]' => 'wrong-password', 'account_closure[confirm]' => true]);
        self::assertSelectorTextContains('#close-account', 'Your current password is not correct.');
        $this->client->submitForm('Close My Account', ['account_closure[password]' => self::PASSWORD, 'account_closure[confirm]' => true]);
        self::assertResponseRedirects('/?account=closed', 303);

        $this->em()->clear();
        $user = $this->em()->find(User::class, $this->customer->getId());
        self::assertSame(['Deleted customer', 'deleted-'.$user->getId().'@deleted.invalid', User::STATUS_INACTIVE], [$user->getName(), $user->getEmail(), $user->getStatus()]);
        self::assertSame(0, $this->em()->getRepository(CustomerDocument::class)->count([]));
        self::assertNotNull($this->em()->find(Booking::class, $booking->getId()), 'the booking is kept for the records');
    }

    public function testIdDocumentsAreDeletedAYearAfterTheLastRentalByTheScheduledSweep(): void
    {
        $booking = $this->confirmedBooking();
        $this->em()->createQuery('UPDATE '.Booking::class.' b SET b.status = :completed, b.pickupAt = :pickup, b.returnAt = :return WHERE b.id = :id')
            ->execute(['completed' => 'completed', 'pickup' => new \DateTimeImmutable('-400 days'), 'return' => new \DateTimeImmutable('-397 days'), 'id' => $booking->getId()]);
        $this->em()->createQuery('UPDATE '.CustomerDocument::class.' d SET d.updatedAt = :old')->execute(['old' => new \DateTimeImmutable('-398 days')]);
        // the sweep shares this entity manager: forget the copies loaded before the updates
        $this->em()->clear();

        // the schedule sends the sweep message; handling it runs the sweep
        $messages = array_map(static fn ($recurring): iterable => $recurring->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'id', $recurring->getTrigger(), new \DateTimeImmutable())), static::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages());
        self::assertInstanceOf(SweepRentals::class, [...$messages[0]][0]);
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new SweepRentals());

        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(CustomerDocument::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['user' => $this->customer->getId(), 'title' => 'ID Documents Removed']));
    }

    public function testTheAuditLogIsSearchableAndAdminListsArePaged(): void
    {
        $this->confirmedBooking();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/audit?action=booking_created');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.audit-table', 'Booking Created');
        self::assertSelectorTextContains('.audit-table', 'Juan Dela Cruz');
        $this->client->request('GET', '/admin/audit?action=nothing-matches-this');
        self::assertSelectorTextContains('.audit-table', 'No audit entries match these filters.');

        for ($i = 1; $i <= 26; ++$i) {
            $this->createUser(sprintf('page-user-%02d@test.vj', $i), name: 'Paged User '.$i);
        }
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('.admin-pagination', 'Page 1 of 2');
        $this->client->click($crawler->filter('.admin-pagination a[rel="next"]')->link());
        self::assertSelectorTextContains('.admin-pagination', 'Page 2 of 2');
    }

    public function testTheInboxEmailsARepliesAndKeepsIt(): void
    {
        $message = (new ContactMessage())->setName('Ana Reyes')->setEmail('ana@test.vj')->setSubject('Corporate rental')->setMessage('Do you offer monthly rates for three vans?');
        $this->em()->persist($message);
        $this->em()->flush();

        $this->client->loginUser($this->admin);
        $this->submit('/admin/messages?status=all', sprintf('form[action^="/admin/messages/%d/reply"]', $message->getId()), ['reply' => 'Yes, we do. Our team will send a quote today.']);
        $emails = self::getMailerMessages();
        self::assertCount(1, $emails);
        /** @var Email $email */
        $email = $emails[0];
        self::assertSame('ana@test.vj', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Our team will send a quote today.', (string) $email->getTextBody());
        self::assertStringContainsString('Do you offer monthly rates', (string) $email->getTextBody());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Reply sent to ana@test.vj.');
        self::assertSelectorTextContains('.admin-message-card', 'Replied');
        $this->em()->clear();
        self::assertSame('read', $this->em()->find(ContactMessage::class, $message->getId())->getStatus());
    }

    private function transfer(Booking $booking, string $reference): void
    {
        $this->client->loginUser($this->customer);
        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        $this->client->request('POST', '/account/payments', [
            '_token' => $crawler->filter('#paymentForm input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'payment_type' => 'deposit',
            'method' => 'gcash',
            'amount' => '5000',
            'transaction_reference' => $reference,
        ], ['payment_proof' => $this->png('receipt.png')]);
    }

    private function promoUses(): int
    {
        $this->em()->clear();

        return $this->em()->getRepository(Promo::class)->findOneBy(['code' => self::PROMO])?->getUsedCount() ?? -1;
    }

    private function clean(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        $em = $this->em();
        $em->createQuery('DELETE FROM '.Promo::class.' p WHERE p.code = :code')->execute(['code' => self::PROMO]);
        $em->createQuery('DELETE FROM '.ContactMessage::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :paged OR u.email LIKE :deleted')->execute(['paged' => 'page-user-%', 'deleted' => 'deleted-%@deleted.invalid']);
    }
}
