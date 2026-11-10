<?php

namespace App\Tests\Controller\Admin;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Administrators view every record staff created, edit and delete any of
 * them, and search and filter the list (staff can search and filter too).
 */
final class ManageRecordsTest extends WebTestCase
{
    use LogsInUsers;

    private KernelBrowser $client;
    private User $sam;
    private User $olive;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRecords();
        $this->sam = $this->createUser('records.sam@test.vj', User::ROLE_STAFF, name: 'Sam Staff');
        $this->olive = $this->createUser('records.olive@test.vj', User::ROLE_STAFF, name: 'Olive Other');
        $this->loginAsAdmin($this->client);
    }

    protected function tearDown(): void
    {
        $this->clearRecords();
        $this->em()->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', ['records.sam@test.vj', 'records.olive@test.vj'])
            ->execute();
        parent::tearDown();
    }

    public function testAdminNavigationLinksToRecordManagement(): void
    {
        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('.admin-nav a[href="/staff/maintenance"]', 'Manage Records');

        $this->client->request('GET', '/staff/maintenance');
        self::assertResponseIsSuccessful();
        // the admin keeps their sidebar on the staff pages, with this link active
        self::assertSelectorTextContains('.admin-nav a.active', 'Manage Records');
    }

    public function testAdminSeesEveryStaffRecordWithEditAndDelete(): void
    {
        $samRecord = $this->createRecord($this->sam, 'Oil change', 'toyota-hiace');
        $oliveRecord = $this->createRecord($this->olive, 'Tyre rotation', 'toyota-fortuner');

        $crawler = $this->client->request('GET', '/staff/maintenance');

        self::assertResponseIsSuccessful();
        foreach ([$samRecord, $oliveRecord] as $record) {
            $row = $crawler->filter(\sprintf('tr[data-record="%d"]', $record->getId()));
            self::assertCount(1, $row);
            self::assertCount(1, $row->filter(\sprintf('a[href="/staff/maintenance/%d/edit"]', $record->getId())));
            self::assertCount(1, $row->filter(\sprintf('a[href="/staff/maintenance/%d/delete"]', $record->getId())));
        }
        self::assertSelectorTextContains('.admin-table', 'Created by Sam Staff');
        self::assertSelectorTextContains('.admin-table', 'Created by Olive Other');
    }

    public function testAdminEditsAnyStaffRecordAndAuthorIsKept(): void
    {
        $record = $this->createRecord($this->sam, 'Oil change', 'toyota-hiace');

        $this->client->request('GET', \sprintf('/staff/maintenance/%d/edit', $record->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save Changes', ['maintenance_record[title]' => 'Oil and filter change']);

        self::assertResponseRedirects(\sprintf('/staff/maintenance/%d', $record->getId()), 303);
        $saved = $this->records()[0];
        self::assertSame('Oil and filter change', $saved->getTitle());
        self::assertSame($this->sam->getId(), $saved->getCreatedBy()->getId(), 'the staff member stays the author');
    }

    public function testAdminDeletesAnyStaffRecordAfterConfirmation(): void
    {
        $record = $this->createRecord($this->olive, 'Tyre rotation', 'toyota-fortuner');

        $this->client->request('GET', \sprintf('/staff/maintenance/%d/delete', $record->getId()));
        self::assertSelectorTextContains('h1', 'Delete this maintenance record?');
        self::assertCount(1, $this->records(), 'the prompt deletes nothing');

        $this->client->submitForm('Yes, Delete Record');

        self::assertResponseRedirects('/staff/maintenance', 303);
        self::assertCount(0, $this->records());
    }

    public function testSearchMatchesVehicleTitleDescriptionAndStaffName(): void
    {
        $oil = $this->createRecord($this->sam, 'Oil change', 'toyota-hiace');
        $tyre = $this->createRecord($this->olive, 'Tyre rotation', 'toyota-fortuner');

        foreach (['oil' => $oil, 'OLIVE' => $tyre, 'fortuner' => $tyre, 'routine' => null] as $query => $only) {
            $crawler = $this->client->request('GET', '/staff/maintenance?q='.urlencode($query));
            $ids = $crawler->filter('tr[data-record]')->each(static fn ($row): int => (int) $row->attr('data-record'));
            sort($ids);

            $expected = null === $only ? [$oil->getId(), $tyre->getId()] : [$only->getId()];
            sort($expected);
            self::assertSame($expected, $ids, "search \"$query\"");
            self::assertInputValueSame('q', $query);
        }

        $this->client->request('GET', '/staff/maintenance?q=nothing-matches');
        self::assertSelectorTextContains('.admin-table', 'No records match your search.');
        self::assertSelectorTextContains('.record-count', 'Showing 0 of 2 records');
        self::assertSelectorExists('a.btn[href="/staff/maintenance"]');
    }

    public function testFiltersByStatus(): void
    {
        $scheduled = $this->createRecord($this->sam, 'Oil change', 'toyota-hiace');
        $done = $this->createRecord($this->olive, 'Tyre rotation', 'toyota-fortuner');
        $done->changeStatus(MaintenanceRecord::STATUS_COMPLETED);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/staff/maintenance?status=completed');
        self::assertSame([$done->getId()], $crawler->filter('tr[data-record]')->each(static fn ($row): int => (int) $row->attr('data-record')));
        self::assertSelectorExists('select[name="status"] option[value="completed"][selected]');

        // search and filter combine
        $crawler = $this->client->request('GET', '/staff/maintenance?status=scheduled&q=oil');
        self::assertSame([$scheduled->getId()], $crawler->filter('tr[data-record]')->each(static fn ($row): int => (int) $row->attr('data-record')));

        // an unknown status is ignored rather than hiding everything
        $crawler = $this->client->request('GET', '/staff/maintenance?status=paused');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tr[data-record]'));
    }

    public function testStaffCanSearchToo(): void
    {
        $this->createRecord($this->sam, 'Oil change', 'toyota-hiace');
        $this->createRecord($this->olive, 'Tyre rotation', 'toyota-fortuner');
        $this->client->loginUser($this->sam);

        $crawler = $this->client->request('GET', '/staff/maintenance?q=tyre');

        self::assertCount(1, $crawler->filter('tr[data-record]'));
        // still no edit link on someone else's record
        self::assertSelectorNotExists('tr[data-record] a[href$="/edit"]');
    }

    private function createRecord(User $author, string $title, string $vehicleSlug): MaintenanceRecord
    {
        $record = (new MaintenanceRecord())
            ->setVehicle($this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => $vehicleSlug]))
            ->setTitle($title)
            ->setDescription('Routine service work.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCreatedBy($this->em()->find(User::class, $author->getId()));
        $this->em()->persist($record);
        $this->em()->flush();

        return $record;
    }

    /** @return list<MaintenanceRecord> */
    private function records(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(MaintenanceRecord::class)->findAll();
    }

    private function clearRecords(): void
    {
        $this->em()->createQuery('DELETE FROM '.MaintenanceRecord::class)->execute();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
