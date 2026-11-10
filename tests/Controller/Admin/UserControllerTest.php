<?php

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    use LogsInUsers;

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // keep the test admin the only active administrator so the last-admin rule is testable
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(User::class)->findBy(['role' => User::ROLE_ADMIN]) as $other) {
            if (self::ADMIN_EMAIL !== $other->getEmail()) {
                $em->remove($other);
            }
        }
        $em->flush();

        $this->admin = $this->loginAsAdmin($this->client);
        $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
    }

    public function testListAndSearch(): void
    {
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-table', 'admin@test.vj • You');

        $crawler = $this->client->request('GET', '/admin/users?q=dela cruz');
        self::assertCount(1, $crawler->filter('.admin-table tbody tr'));
        self::assertSelectorTextContains('.admin-table', 'juan@test.vj');

        $this->client->request('GET', '/admin/users?q=nobody-matches');
        self::assertSelectorTextContains('.empty-state h3', 'No accounts found');
    }

    public function testPromoteCustomerToStaff(): void
    {
        $this->submitAccessForm('juan@test.vj', 'staff', 'active');

        self::assertResponseRedirects('/admin/users');
        $juan = $this->users()->findOneBy(['email' => 'juan@test.vj']);
        self::assertSame(User::ROLE_STAFF, $juan->getRole());
        self::assertSame(['ROLE_USER', 'ROLE_STAFF'], $juan->getRoles());

        $this->client->followRedirect();
        self::assertSelectorExists('select[name="role"] option[value="staff"][selected]');
    }

    public function testDeactivateCustomer(): void
    {
        $this->submitAccessForm('juan@test.vj', 'customer', 'inactive');

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Account access updated for Juan Dela Cruz.');
        self::assertSame('inactive', $this->users()->findOneBy(['email' => 'juan@test.vj'])->getStatus());
    }

    public function testCannotRemoveOwnAdministratorAccess(): void
    {
        $this->submitAccessForm(self::ADMIN_EMAIL, 'customer', 'active');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'You cannot remove or deactivate your own administrator access.');
        self::assertTrue($this->users()->findOneBy(['email' => self::ADMIN_EMAIL])->isAdmin());
    }

    private function submitAccessForm(string $email, string $role, string $status): void
    {
        $crawler = $this->client->request('GET', '/admin/users?q='.urlencode($email));
        $form = $crawler->filter('.admin-inline-form')->form(['role' => $role, 'status' => $status]);
        $this->client->submit($form);
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }
}
