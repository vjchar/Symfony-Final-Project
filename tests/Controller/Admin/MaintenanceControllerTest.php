<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MaintenanceControllerTest extends WebTestCase
{
    use LogsInUsers;

    private const VEHICLE_SLUG = 'toyota-hiace';

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRecords();
        $this->admin = $this->loginAsAdmin($this->client);
        $this->vehicle()->setIsActive(true)->setAvailabilityStatus('available');
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        // records point at the admin user, which other tests delete
        $this->clearRecords();
        $this->vehicle()->setIsActive(true)->setAvailabilityStatus('available');
        $this->em()->flush();
        parent::tearDown();
    }

    public function testShowsTheEmptyV15Page(): void
    {
        $crawler = $this->client->request('GET', '/admin/maintenance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Maintenance records');
        self::assertSelectorTextContains('.admin-table tbody', 'No maintenance records yet.');
        self::assertInputValueSame('maintenance_record[cost]', '0');
        self::assertSelectorExists('input[name="maintenance_record[startsAt]"][type="datetime-local"]');
        // only active vehicles can be scheduled
        $inactive = $this->em()->getRepository(Vehicle::class)->findOneBy(['isActive' => false]);
        if (null !== $inactive) {
            self::assertCount(0, $crawler->filter(sprintf('#maintenance_record_vehicle option[value="%d"]', $inactive->getId())));
        }
    }

    public function testNavLinksToMaintenance(): void
    {
        $this->client->request('GET', '/admin');

        self::assertSelectorExists('a[href="/admin/maintenance"]');
    }

    public function testSchedulesARecord(): void
    {
        $this->client->request('GET', '/admin/maintenance');
        $this->client->submitForm('Create Record', [
            'maintenance_record[vehicle]' => (string) $this->vehicle()->getId(),
            'maintenance_record[title]' => 'Oil change',
            'maintenance_record[startsAt]' => '2026-11-20T09:30',
            'maintenance_record[description]' => 'Replace engine oil and filter.',
            'maintenance_record[cost]' => '2500',
        ]);

        self::assertResponseRedirects('/admin/maintenance', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Maintenance record saved.');
        self::assertSelectorTextContains('.admin-table tbody td strong', $this->vehicle()->getName());
        self::assertSelectorTextContains('.admin-table tbody', 'Created by Vina Admin');
        self::assertSelectorTextContains('.admin-table tbody', 'Nov 20, 2026 9:30 AM');
        self::assertSelectorTextContains('.admin-table tbody', '₱2,500');
        self::assertSelectorTextContains('.admin-table .status-badge--warning', 'Scheduled');

        $record = $this->records()[0];
        self::assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->getStatus());
        self::assertSame($this->admin->getId(), $record->getCreatedBy()->getId());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'maintenance_created', 'entityId' => $record->getId()]));
        // scheduling alone keeps the vehicle bookable
        self::assertSame('available', $this->vehicle()->getAvailabilityStatus());
    }

    public function testInvalidFieldsShareOneV15Message(): void
    {
        $this->client->request('GET', '/admin/maintenance');
        $this->client->submitForm('Create Record', [
            'maintenance_record[title]' => 'Oi',
            'maintenance_record[startsAt]' => '',
            'maintenance_record[description]' => 'Oil',
            'maintenance_record[cost]' => '-5',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(1, '.alert-danger');
        self::assertSelectorTextContains('.alert-danger', 'Complete all maintenance fields with valid values.');
        self::assertCount(0, $this->records());
    }

    public function testInProgressTakesTheVehicleOfflineAndCompletionBringsItBack(): void
    {
        $record = $this->createRecord();

        $this->updateStatus('In Progress');
        self::assertResponseRedirects('/admin/maintenance', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Maintenance record saved.');
        self::assertSelectorTextContains('.admin-table .status-badge--info', 'In Progress');
        self::assertSame('maintenance', $this->vehicle()->getAvailabilityStatus());
        self::assertNull($this->records()[0]->getEndsAt());

        $this->updateStatus('Completed');
        self::assertSame('available', $this->vehicle()->getAvailabilityStatus());
        self::assertNotNull($this->records()[0]->getEndsAt());

        $audit = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'maintenance_status_updated', 'entityId' => $record->getId()], ['id' => 'DESC']);
        self::assertSame(['status' => 'completed'], $audit->getMetadata());
    }

    public function testCancellingKeepsAnInactiveVehicleUnavailable(): void
    {
        $this->createRecord();
        $this->updateStatus('In Progress');
        $this->vehicle()->setIsActive(false);
        $this->em()->flush();

        $this->updateStatus('Cancelled');

        self::assertSame('unavailable', $this->vehicle()->getAvailabilityStatus());
    }

    public function testRejectsAnInvalidStatusOrToken(): void
    {
        $record = $this->createRecord();

        $this->client->request('GET', '/admin/maintenance');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $this->client->request('POST', $form->getUri(), ['status' => 'paused'] + $form->getValues());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid maintenance update.');

        $this->client->request('POST', sprintf('/admin/maintenance/%d/status', $record->getId()), ['status' => 'completed', '_token' => 'wrong']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Your session expired.');

        self::assertSame(MaintenanceRecord::STATUS_SCHEDULED, $this->records()[0]->getStatus());
    }

    private function updateStatus(string $label): void
    {
        $this->client->request('GET', '/admin/maintenance');
        $this->client->submitForm('Save', ['status' => strtolower(str_replace(' ', '_', $label))]);
    }

    private function createRecord(): MaintenanceRecord
    {
        $record = (new MaintenanceRecord())
            ->setVehicle($this->vehicle())
            ->setTitle('Brake inspection')
            ->setDescription('Check pads and rotors.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCost(1800)
            ->setCreatedBy($this->em()->find(User::class, $this->admin->getId()));
        $this->em()->persist($record);
        $this->em()->flush();

        return $record;
    }

    private function vehicle(): Vehicle
    {
        $vehicle = $this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => self::VEHICLE_SLUG]);
        self::assertNotNull($vehicle, 'the v15 fleet is seeded');

        return $vehicle;
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
