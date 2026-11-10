<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Payment;
use App\Entity\User;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin Calendar, Admin Reports, Health and the old admin-login address.
 */
final class OperationsTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;
    use UploadsFiles;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->clearCustomerRecords();
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testCalendarShowsPickupsAndReturns(): void
    {
        $reference = $this->bookAsCustomer();
        $this->loginAsAdmin($this->client);

        $this->client->request('GET', '/admin/calendar?view=week&date='.$this->day(2));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.admin-nav a.active[href="/admin/calendar"]');
        self::assertSelectorTextContains('.calendar-agenda', 'Toyota Fortuner — Pickup');
        self::assertSelectorTextContains('.calendar-event-details', $reference);
        self::assertSelectorTextContains('.calendar-event-details', 'Juan Dela Cruz');

        $this->client->request('GET', '/admin/calendar?view=day&type=return&date='.$this->day(5));
        self::assertSelectorCount(1, '.calendar-detail-card');
        self::assertSelectorTextContains('.calendar-detail-card h3', 'Toyota Fortuner — Scheduled return');

        // the month grid always starts on a Monday and covers whole weeks
        $crawler = $this->client->request('GET', '/admin/calendar?date=nonsense&type=nonsense');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.calendar-days .calendar-day')->count() % 7);
        self::assertSelectorTextContains('.calendar-navigation h2', (new \DateTimeImmutable())->format('F Y'));
    }

    public function testReportsCountBookingsAndOnlyVerifiedPayments(): void
    {
        $reference = $this->bookAsCustomer();
        $this->em()->persist(new Payment($this->bookings()[0], $this->em()->getRepository(User::class)->findOneBy(['email' => 'juan@test.vj']), Payment::TYPE_DEPOSIT, Payment::METHOD_CASH, 5000, '', ''));
        $this->em()->flush();
        $this->loginAsAdmin($this->client);

        $range = sprintf('from=%s&to=%s', (new \DateTimeImmutable('-1 day'))->format('Y-m-d'), (new \DateTimeImmutable('+1 day'))->format('Y-m-d'));
        $this->client->request('GET', '/admin/reports?'.$range);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-stats', '₱18,600');
        self::assertSelectorTextContains('.admin-table tbody', $reference);
        self::assertSelectorTextContains('.report-bars', 'Pending 1');
        self::assertSelectorTextContains('.dashboard-panel:last-child .report-bar', 'Toyota Fortuner');
        // the deposit is still pending, so it is not counted
        self::assertSelectorTextContains('.admin-stats', '₱0');

        $this->em()->createQuery('UPDATE '.Payment::class.' p SET p.status = :paid')->execute(['paid' => Payment::STATUS_PAID]);
        $this->client->request('GET', '/admin/reports?'.$range.'&export=csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=rental-report-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('Reference,Customer,Vehicle,Pickup,"Original Return","Current Return",Status,"Booking Total","Verified Payments"', $csv);
        self::assertStringContainsString($reference.',"Juan Dela Cruz","Toyota Fortuner"', $csv);
        self::assertStringContainsString(',pending,18600,5000', $csv);

        // a reversed range falls back to this month, like v15
        $this->client->request('GET', '/admin/reports?from=2026-12-31&to=2026-01-01');
        self::assertSelectorExists('#reportFrom[value="'.(new \DateTimeImmutable('first day of this month'))->format('Y-m-d').'"]');
    }

    public function testHealthPageRunsTheChecks(): void
    {
        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/admin/health');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.health-grid', 'MySQL connection');
        self::assertSelectorTextContains('.health-check.is-passed', 'PHP version');
        self::assertSelectorExists('.health-check.is-passed strong:contains("Database name")');
        // the test environment runs with debug on, which the page reports as a warning
        self::assertSelectorTextContains('.admin-page-heading .status-badge', 'Action required');
    }

    public function testOldAdminLoginAddressRedirects(): void
    {
        $this->client->request('GET', '/admin-login');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser('juan@test.vj'));
        $this->client->request('GET', '/admin-login');
        self::assertResponseRedirects('/account');

        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/admin-login');
        self::assertResponseRedirects('/admin');
    }

    public function testAdminNavigationHasNoComingSoonLinksLeft(): void
    {
        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/admin');

        self::assertSelectorNotExists('.admin-nav a[href^="/coming-soon/"]');
        self::assertSelectorExists('.admin-nav a[href="/admin/payments"]');
        self::assertSelectorExists('.admin-nav a[href="/admin/health"]');
    }

    private function bookAsCustomer(): string
    {
        $this->client->loginUser($this->createUser('juan@test.vj', name: 'Juan Dela Cruz'));
        $this->submitBooking($this->client);

        return $this->bookings()[0]->getReference();
    }
}
