<?php

namespace App\Tests\Controller\Staff;

use App\Entity\AuditLog;
use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Staff create and read maintenance records, and edit or delete only their own.
 */
final class MaintenanceControllerTest extends WebTestCase
{
    use LogsInUsers;

    private const VEHICLE_SLUG = 'toyota-hiace';

    private KernelBrowser $client;
    private User $staff;
    private User $otherStaff;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRecords();
        $this->staff = $this->createUser('staff@test.vj', User::ROLE_STAFF, name: 'Sam Staff');
        $this->otherStaff = $this->createUser('staff2@test.vj', User::ROLE_STAFF, name: 'Olive Other');
        $this->vehicle()->setIsActive(true)->setAvailabilityStatus('available');
        $this->em()->flush();
        $this->client->loginUser($this->staff);
    }

    protected function tearDown(): void
    {
        $this->clearRecords();
        $this->vehicle()->setIsActive(true)->setAvailabilityStatus('available');
        $this->em()->flush();
        parent::tearDown();
    }

    public function testStaffGetStaffRoleOnly(): void
    {
        self::assertSame(['ROLE_USER', 'ROLE_STAFF'], $this->staff->getRoles());
    }

    public function testListsEveryRecordButOffersChangesOnlyOnOwnRecords(): void
    {
        $mine = $this->createRecord($this->staff, 'Oil change');
        $theirs = $this->createRecord($this->otherStaff, 'Tyre rotation');

        $crawler = $this->client->request('GET', '/staff/maintenance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Maintenance records');
        $mineRow = $crawler->filter(sprintf('tr[data-record="%d"]', $mine->getId()));
        $theirsRow = $crawler->filter(sprintf('tr[data-record="%d"]', $theirs->getId()));

        self::assertStringContainsString('Created by you', $mineRow->text());
        self::assertCount(1, $mineRow->filter(sprintf('a[href="/staff/maintenance/%d/edit"]', $mine->getId())));
        self::assertCount(1, $mineRow->filter(sprintf('a[href="/staff/maintenance/%d/delete"]', $mine->getId())));

        self::assertStringContainsString('Created by Olive Other', $theirsRow->text());
        self::assertCount(1, $theirsRow->filter(sprintf('a[href="/staff/maintenance/%d"]', $theirs->getId())));
        self::assertCount(0, $theirsRow->filter('a[href$="/edit"], a[href$="/delete"]'));
    }

    public function testCreatesARecordOwnedByTheStaffMember(): void
    {
        $this->client->request('GET', '/staff/maintenance/new');
        $this->client->submitForm('Create Record', [
            'maintenance_record[vehicle]' => (string) $this->vehicle()->getId(),
            'maintenance_record[title]' => 'Oil change',
            'maintenance_record[startsAt]' => '2026-11-20T09:30',
            'maintenance_record[description]' => 'Replace engine oil and filter.',
            'maintenance_record[cost]' => '2500',
        ]);

        $record = $this->records()[0];
        self::assertResponseRedirects(sprintf('/staff/maintenance/%d', $record->getId()), 303);
        self::assertSame($this->staff->getId(), $record->getCreatedBy()->getId());
        self::assertSame('Oil change', $record->getTitle());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'maintenance_created', 'entityId' => $record->getId()]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Maintenance record saved.');
        self::assertSelectorTextContains('h1', 'Oil change');
    }

    public function testInvalidRecordIsRejected(): void
    {
        $this->client->request('GET', '/staff/maintenance/new');
        $this->client->submitForm('Create Record', [
            'maintenance_record[title]' => 'Oi',
            'maintenance_record[startsAt]' => '',
            'maintenance_record[description]' => 'Oil',
            'maintenance_record[cost]' => '-5',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', MaintenanceRecord::INVALID);
        self::assertCount(0, $this->records());
    }

    public function testViewsAnotherStaffMembersRecordReadOnly(): void
    {
        $theirs = $this->createRecord($this->otherStaff, 'Tyre rotation');

        $this->client->request('GET', sprintf('/staff/maintenance/%d', $theirs->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tyre rotation');
        self::assertSelectorTextContains('.operation-meta', 'Olive Other');
        self::assertSelectorNotExists('a[href$="/edit"]');
        self::assertSelectorNotExists('a[href$="/delete"]');
    }

    public function testEditsOwnRecord(): void
    {
        $mine = $this->createRecord($this->staff, 'Oil change');

        $this->client->request('GET', sprintf('/staff/maintenance/%d/edit', $mine->getId()));
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('maintenance_record[title]', 'Oil change');

        $this->client->submitForm('Save Changes', [
            'maintenance_record[title]' => 'Oil and filter change',
            'maintenance_record[cost]' => '3200',
        ]);

        self::assertResponseRedirects(sprintf('/staff/maintenance/%d', $mine->getId()), 303);
        $record = $this->records()[0];
        self::assertSame('Oil and filter change', $record->getTitle());
        self::assertSame(3200, $record->getCost());
        self::assertSame($this->staff->getId(), $record->getCreatedBy()->getId());
    }

    public function testCannotEditAnotherStaffMembersRecord(): void
    {
        $theirs = $this->createRecord($this->otherStaff, 'Tyre rotation');
        $url = sprintf('/staff/maintenance/%d/edit', $theirs->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $url, ['maintenance_record' => ['title' => 'Hijacked']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Tyre rotation', $this->records()[0]->getTitle());
    }

    public function testDeletingOwnRecordAsksForConfirmationFirst(): void
    {
        $mine = $this->createRecord($this->staff, 'Oil change');

        $this->client->request('GET', sprintf('/staff/maintenance/%d', $mine->getId()));
        $this->client->clickLink('Delete');

        // the prompt itself deletes nothing
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Delete this maintenance record?');
        self::assertSelectorTextContains('.admin-editor', 'This cannot be undone.');
        self::assertSelectorExists('a[href="/staff/maintenance/'.$mine->getId().'"]');
        self::assertCount(1, $this->records());

        $this->client->submitForm('Yes, Delete Record');

        self::assertResponseRedirects('/staff/maintenance', 303);
        self::assertCount(0, $this->records());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'maintenance_deleted', 'entityId' => $mine->getId()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Maintenance record deleted.');
    }

    public function testDeleteNeedsAValidToken(): void
    {
        $mine = $this->createRecord($this->staff, 'Oil change');

        $this->client->request('POST', sprintf('/staff/maintenance/%d/delete', $mine->getId()), ['_token' => 'wrong']);

        self::assertResponseRedirects(sprintf('/staff/maintenance/%d/delete', $mine->getId()), 303);
        self::assertCount(1, $this->records());
    }

    public function testDeletingWorkInProgressPutsTheVehicleBack(): void
    {
        $mine = $this->createRecord($this->staff, 'Engine repair');
        $mine->changeStatus(MaintenanceRecord::STATUS_IN_PROGRESS);
        $this->em()->flush();
        self::assertSame('maintenance', $this->vehicle()->getAvailabilityStatus());

        $this->client->request('GET', sprintf('/staff/maintenance/%d/delete', $mine->getId()));
        self::assertSelectorTextContains('.admin-editor', 'The vehicle will be returned to availability.');
        $this->client->submitForm('Yes, Delete Record');

        self::assertCount(0, $this->records());
        self::assertSame('available', $this->vehicle()->getAvailabilityStatus());
    }

    public function testCannotDeleteAnotherStaffMembersRecord(): void
    {
        $theirs = $this->createRecord($this->otherStaff, 'Tyre rotation');
        $url = sprintf('/staff/maintenance/%d/delete', $theirs->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        // even with a token for that record, ownership is checked first
        $this->client->request('POST', $url, ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->records());
    }

    /**
     * Typing an administrator address by hand answers 403, and the error page
     * points staff back to their own workspace.
     */
    #[DataProvider('adminUrls')]
    public function testStaffAreForbiddenFromAdminPages(string $method, string $url): void
    {
        $record = $this->createRecord($this->staff, 'Oil change');

        $this->client->request($method, str_replace('{id}', (string) $record->getId(), $url), 'POST' === $method ? ['status' => 'completed'] : []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(MaintenanceRecord::STATUS_SCHEDULED, $this->records()[0]->getStatus());
    }

    /** @return iterable<string, array{string, string}> */
    public static function adminUrls(): iterable
    {
        yield 'dashboard' => ['GET', '/admin'];
        yield 'users' => ['GET', '/admin/users'];
        yield 'create user' => ['GET', '/admin/users/new'];
        yield 'bookings' => ['GET', '/admin/bookings'];
        yield 'payments' => ['GET', '/admin/payments'];
        yield 'vehicles' => ['GET', '/admin/vehicles'];
        yield 'maintenance' => ['GET', '/admin/maintenance'];
        yield 'maintenance status change' => ['POST', '/admin/maintenance/{id}/status'];
        yield 'role change' => ['POST', '/admin/users'];
    }

    /**
     * The test kernel runs in debug mode and shows Symfony's exception page, so
     * the production 403 template is rendered directly as a staff member.
     */
    public function testForbiddenPageLinksStaffToTheirWorkspace(): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($this->staff, 'main', $this->staff->getRoles()));
        $request = Request::create('/admin');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $html = $container->get('twig')->render('@Twig/Exception/error403.html.twig');

        $page = new Crawler($html);
        self::assertSame('Go to Staff Workspace', trim($page->filter('.error-page a[href="/staff"]')->text()));
        self::assertCount(0, $page->filter('.error-page a[href="/account"]'));
    }

    public function testStaffAreForbiddenFromCustomerPages(): void
    {
        $this->client->request('GET', '/account');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSignedInStaffAreSentToTheirWorkspace(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/staff');

        $this->client->request('GET', '/admin-login');
        self::assertResponseRedirects('/staff');
    }

    public function testCustomersCannotOpenTheStaffWorkspace(): void
    {
        $this->client->loginUser($this->createUser('customer@test.vj'));

        $this->client->request('GET', '/staff/maintenance');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGuestsAreSentToSignIn(): void
    {
        $this->client->restart();

        $this->client->request('GET', '/staff/maintenance');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function createRecord(User $author, string $title): MaintenanceRecord
    {
        $record = (new MaintenanceRecord())
            ->setVehicle($this->vehicle())
            ->setTitle($title)
            ->setDescription('Routine service work.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCost(1800)
            ->setCreatedBy($this->em()->find(User::class, $author->getId()));
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
