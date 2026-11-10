<?php

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationTest extends WebTestCase
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

    public function testInboxListsUpdatesWithTheirNextStepAndMarksThemRead(): void
    {
        $this->client->request('GET', '/account/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state h2', 'No notifications yet');

        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $customer = $this->em()->find(User::class, $this->customer->getId());
        $this->em()->persist(new Notification($customer, 'Payment Required', 'Pay the deposit for '.$booking->getReference().'.', 'payment', $booking));
        $this->em()->persist(new Notification($customer, 'Document Verified', 'Your government id was verified successfully.', 'document'));
        $this->em()->flush();

        $this->client->request('GET', '/account/notifications');
        self::assertSelectorCount(2, '.notification-item.is-unread');
        self::assertSelectorTextContains('.notification-link span', '2');
        self::assertSelectorExists(sprintf('.notification-item a[href="/account/payments?reference=%s"]', $booking->getReference()));
        self::assertSelectorTextContains('.notification-list', 'Proceed to Payment');
        self::assertSelectorExists('.notification-item a[href="/account/documents"]');

        $this->client->submitForm('Mark All Read');
        self::assertResponseRedirects('/account/notifications', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'All notifications marked as read.');
        self::assertSelectorNotExists('.notification-item.is-unread');
        self::assertSelectorNotExists('.notification-link span');
    }

    public function testAdministratorsHaveAnInboxToo(): void
    {
        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/account/notifications');
        self::assertResponseIsSuccessful();
    }

    public function testGuestsMustSignIn(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/account/notifications');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
