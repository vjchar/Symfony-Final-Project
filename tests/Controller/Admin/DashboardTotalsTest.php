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
 * The dashboard's Total users, Total staff and Total records cards.
 */
final class DashboardTotalsTest extends WebTestCase
{
    use LogsInUsers;

    private const EMAILS = ['totals.staff@test.vj', 'totals.inactive@test.vj', 'totals.customer@test.vj'];

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

    public function testTotalsMatchTheDatabase(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame($this->countUsers(), $this->stat('total-users'));
        self::assertSame($this->countUsers(User::ROLE_STAFF), $this->stat('total-staff'));
        self::assertSame($this->countRecords(), $this->stat('total-records'));
    }

    public function testTotalsCountEveryRoleAndStatus(): void
    {
        $this->client->request('GET', '/admin');
        [$users, $staff, $records] = [$this->stat('total-users'), $this->stat('total-staff'), $this->stat('total-records')];

        $activeStaff = $this->createUser('totals.staff@test.vj', User::ROLE_STAFF);
        $this->createUser('totals.inactive@test.vj', User::ROLE_STAFF, User::STATUS_INACTIVE);
        $this->createUser('totals.customer@test.vj');
        $this->em()->persist((new MaintenanceRecord())
            ->setVehicle($this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => 'toyota-hiace']))
            ->setTitle('Oil change')
            ->setDescription('Routine service work.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCreatedBy($activeStaff));
        $this->em()->flush();

        $this->client->request('GET', '/admin');

        // inactive accounts still count towards the totals
        self::assertSame($users + 3, $this->stat('total-users'));
        self::assertSame($staff + 2, $this->stat('total-staff'));
        self::assertSame($records + 1, $this->stat('total-records'));
        self::assertSelectorTextContains('[data-stat="total-staff"]', 'active staff');
        self::assertSelectorTextContains('[data-stat="total-records"]', 'maintenance');
    }

    private function stat(string $key): int
    {
        $card = $this->client->getCrawler()->filter(\sprintf('[data-stat="%s"]', $key));
        self::assertCount(1, $card, "the $key card is shown");

        return (int) $card->filter('strong')->text();
    }

    private function countUsers(?string $role = null): int
    {
        return $this->em()->getRepository(User::class)->count(null === $role ? [] : ['role' => $role]);
    }

    private function countRecords(): int
    {
        return $this->em()->getRepository(MaintenanceRecord::class)->count([]);
    }

    private function cleanUp(): void
    {
        $this->em()->createQuery('DELETE FROM '.MaintenanceRecord::class.' m WHERE m.createdBy IN (SELECT u FROM '.User::class.' u WHERE u.email IN (:emails))')
            ->setParameter('emails', self::EMAILS)
            ->execute();
        $this->em()->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', self::EMAILS)
            ->execute();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
