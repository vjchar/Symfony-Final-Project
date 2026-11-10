<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Addon;
use App\Entity\AuditLog;
use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Administrators delete vehicles and add-ons after a confirmation prompt;
 * ones that bookings or maintenance still point at are kept.
 */
final class CatalogDeleteTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;

    private const SLUG = 'test-delete-sedan';
    private const KEY = 'test-delete-rack';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
        $this->loginAsAdmin($this->client);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testDeletesAnUnusedVehicleAfterConfirmation(): void
    {
        $vehicle = $this->createVehicle();
        $crawler = $this->client->request('GET', '/admin/vehicles?page='.$this->vehiclePage($vehicle));

        $form = $crawler->filter(\sprintf('form[action="/admin/vehicles/%d/delete"]', $vehicle->getId()));
        self::assertCount(1, $form, 'each row has a delete form');
        // app.js shows this text in a confirm() dialog before the form is sent
        self::assertSame('Delete Test Delete Sedan? This action cannot be undone.', $form->attr('data-confirm'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/admin/vehicles', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Vehicle Test Delete Sedan was deleted.');
        self::assertNull($this->findVehicle());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'vehicle_deleted', 'entityId' => $vehicle->getId()]));
    }

    public function testKeepsAVehicleWithHistory(): void
    {
        $vehicle = $this->createVehicle();
        $this->em()->persist((new MaintenanceRecord())
            ->setVehicle($vehicle)
            ->setTitle('Oil change')
            ->setDescription('Routine service work.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCreatedBy($this->em()->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL])));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/vehicles?page='.$this->vehiclePage($vehicle));
        $this->client->submit($crawler->filter(\sprintf('form[action="/admin/vehicles/%d/delete"]', $vehicle->getId()))->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Test Delete Sedan has bookings, saved bookings, reviews or maintenance records, so it cannot be deleted. Mark it inactive instead.');
        self::assertNotNull($this->findVehicle());
    }

    public function testVehicleDeleteNeedsAValidToken(): void
    {
        $vehicle = $this->createVehicle();

        $this->client->request('POST', \sprintf('/admin/vehicles/%d/delete', $vehicle->getId()), ['_token' => 'wrong']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Your session expired.');
        self::assertNotNull($this->findVehicle());
    }

    public function testDeletesAnUnusedAddonAfterConfirmation(): void
    {
        $addon = $this->createAddon();
        $crawler = $this->client->request('GET', '/admin/addons');

        $form = $crawler->filter(\sprintf('form[action="/admin/addons/%d/delete"]', $addon->getId()));
        self::assertSame('Delete the Test roof rack add-on? This action cannot be undone.', $form->attr('data-confirm'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/admin/addons', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Rental add-on Test roof rack was deleted.');
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(Addon::class)->findOneBy(['addonKey' => self::KEY]));
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'addon_deleted', 'entityId' => $addon->getId()]));
    }

    public function testKeepsAnAddonThatIsOnABooking(): void
    {
        $this->client->loginUser($this->createUser('catalog.customer@test.vj'));
        $this->submitBooking($this->client, ['addons' => ['gps']]);
        self::assertCount(1, $this->bookings());

        $this->loginAsAdmin($this->client);
        $gps = $this->em()->getRepository(Addon::class)->findOneBy(['addonKey' => 'gps']);
        $crawler = $this->client->request('GET', '/admin/addons');
        $this->client->submit($crawler->filter(\sprintf('form[action="/admin/addons/%d/delete"]', $gps->getId()))->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'is on existing bookings, so it cannot be deleted. Mark it inactive instead.');
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Addon::class)->findOneBy(['addonKey' => 'gps']));
    }

    public function testOnlyAdministratorsCanDelete(): void
    {
        $vehicle = $this->createVehicle();
        $addon = $this->createAddon();

        foreach ([User::ROLE_STAFF, User::ROLE_CUSTOMER] as $role) {
            $this->client->loginUser($this->createUser('catalog.customer@test.vj', $role));
            $this->client->request('POST', \sprintf('/admin/vehicles/%d/delete', $vehicle->getId()), ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', \sprintf('/admin/addons/%d/delete', $addon->getId()), ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertNotNull($this->findVehicle());
    }

    private function createVehicle(): Vehicle
    {
        $this->client->request('GET', '/admin/vehicles');
        $this->client->submitForm('Create Vehicle', [
            'vehicle[name]' => 'Test Delete Sedan',
            'vehicle[slug]' => self::SLUG,
            'vehicle[brand]' => 'Toyota',
            'vehicle[category]' => 'SUV',
            'vehicle[price]' => 1800,
            'vehicle[deposit]' => 5000,
            'vehicle[dailyKm]' => 200,
            'vehicle[seats]' => 5,
            'vehicle[availabilityStatus]' => 'available',
            'vehicle[doors]' => 4,
            'vehicle[transmission]' => 'Automatic',
            'vehicle[fuel]' => 'Gasoline',
            'vehicle[luggage]' => '2 bags',
            'vehicle[image]' => 'toyota-fortuner.png',
            'vehicle[overviewTitle]' => 'An easy city car',
            'vehicle[overview]' => 'A compact sedan that is easy to park and light on fuel.',
            'vehicle[description]' => 'A test vehicle that exists only to be deleted.',
            'vehicle[inclusions]' => 'Standard rental protection',
            'vehicle[features]' => 'Bluetooth audio',
        ]);
        self::assertResponseRedirects();

        return $this->findVehicle() ?? self::fail('the test vehicle was created');
    }

    private function createAddon(): Addon
    {
        $this->client->request('GET', '/admin/addons');
        $this->client->submitForm('Save Add-on', [
            'addon[addonKey]' => self::KEY,
            'addon[name]' => 'Test roof rack',
            'addon[price]' => 300,
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();

        return $this->em()->getRepository(Addon::class)->findOneBy(['addonKey' => self::KEY]) ?? self::fail('the test add-on was created');
    }

    private function findVehicle(): ?Vehicle
    {
        $this->em()->clear();

        return $this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => self::SLUG]);
    }

    /** The admin list shows twelve vehicles per page in id order. */
    private function vehiclePage(Vehicle $vehicle): int
    {
        $before = (int) $this->em()->createQuery('SELECT COUNT(v) FROM '.Vehicle::class.' v WHERE v.id < :id')
            ->setParameter('id', $vehicle->getId())
            ->getSingleScalarResult();

        return intdiv($before, 12) + 1;
    }

    private function cleanUp(): void
    {
        $this->clearBookings();
        $em = $this->em();
        $em->createQuery('DELETE FROM '.MaintenanceRecord::class.' m WHERE m.vehicle IN (SELECT v FROM '.Vehicle::class.' v WHERE v.slug = :slug)')
            ->setParameter('slug', self::SLUG)->execute();
        $em->createQuery('DELETE FROM '.Vehicle::class.' v WHERE v.slug = :slug')->setParameter('slug', self::SLUG)->execute();
        $em->createQuery('DELETE FROM '.Addon::class.' a WHERE a.addonKey = :key')->setParameter('key', self::KEY)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')->setParameter('email', 'catalog.customer@test.vj')->execute();
    }
}
