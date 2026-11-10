<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Service\AdminUserManager;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Administrators create accounts of every role, edit names and emails, set a
 * new password, and delete accounts after a confirmation step.
 */
final class UserManagementTest extends WebTestCase
{
    use LogsInUsers;

    private const NEW_PASSWORD = 'Fresh2026Pass';
    private const TEST_EMAILS = ['juan@test.vj', 'maria@test.vj', 'new.staff@test.vj', 'new.admin@test.vj', 'juan.new@test.vj'];

    private KernelBrowser $client;
    private User $admin;
    private User $juan;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRecords();
        foreach (self::TEST_EMAILS as $email) {
            if (null !== $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email])) {
                $this->em()->remove($user);
            }
        }
        $this->em()->flush();

        $this->admin = $this->loginAsAdmin($this->client);
        $this->juan = $this->createUser('juan@test.vj', name: 'Juan Dela Cruz');
        $this->createUser('maria@test.vj', name: 'Maria Santos');
    }

    protected function tearDown(): void
    {
        $this->clearRecords();
        // other tests count active administrators, so leave none behind
        $this->em()->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', self::TEST_EMAILS)
            ->execute();
        parent::tearDown();
    }

    public function testListShowsTheDateEachAccountWasCreated(): void
    {
        $crawler = $this->client->request('GET', '/admin/users?q=juan@test.vj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-table thead', 'Date created');
        $cell = $crawler->filter(\sprintf('tr[data-user="%d"] td[data-label="Date created"] time', $this->juan->getId()));
        self::assertSame($this->juan->getCreatedAt()->format('M j, Y'), $cell->text());
        self::assertSame($this->juan->getCreatedAt()->format('Y-m-d'), $cell->attr('datetime'));
    }

    public function testListLinksToEveryAction(): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $row = $crawler->filter(\sprintf('tr[data-user="%d"]', $this->juan->getId()));

        self::assertSelectorExists('a[href="/admin/users/new"]');
        self::assertCount(1, $row->filter(\sprintf('a[href="/admin/users/%d/edit"]', $this->juan->getId())));
        self::assertCount(1, $row->filter(\sprintf('a[href="/admin/users/%d/password"]', $this->juan->getId())));
        self::assertCount(1, $row->filter(\sprintf('a[href="/admin/users/%d/delete"]', $this->juan->getId())));
        // no delete link on your own row
        self::assertCount(0, $crawler->filter(\sprintf('a[href="/admin/users/%d/delete"]', $this->admin->getId())));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function newAccounts(): iterable
    {
        yield 'staff' => ['new.staff@test.vj', User::ROLE_STAFF, ['ROLE_USER', 'ROLE_STAFF']];
        yield 'admin' => ['new.admin@test.vj', User::ROLE_ADMIN, ['ROLE_USER', 'ROLE_ADMIN']];
    }

    /**
     * @param list<string> $roles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('newAccounts')]
    public function testCreatesAnAccount(string $email, string $role, array $roles): void
    {
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create Account', [
            'admin_user[name]' => 'New Person',
            'admin_user[email]' => strtoupper($email),
            'admin_user[phone]' => '0917 555 0101',
            'admin_user[role]' => $role,
            'admin_user[plainPassword][password]' => self::NEW_PASSWORD,
            'admin_user[plainPassword][password_confirmation]' => self::NEW_PASSWORD,
        ]);

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Account created for New Person.');

        $user = $this->user($email);
        self::assertNotNull($user);
        self::assertSame($role, $user->getRole());
        self::assertSame($roles, $user->getRoles());
        self::assertTrue($user->isActive());
        self::assertTrue($this->hasher()->isPasswordValid($user, self::NEW_PASSWORD));
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'user_created', 'entityId' => $user->getId()]));
    }

    public function testNewAccountCanSignIn(): void
    {
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create Account', [
            'admin_user[name]' => 'New Staff',
            'admin_user[email]' => 'new.staff@test.vj',
            'admin_user[role]' => User::ROLE_STAFF,
            'admin_user[plainPassword][password]' => self::NEW_PASSWORD,
            'admin_user[plainPassword][password_confirmation]' => self::NEW_PASSWORD,
        ]);

        $this->client->restart();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign In', ['email' => 'new.staff@test.vj', 'password' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/staff/maintenance');
    }

    public function testRejectsADuplicateEmailOrWeakPassword(): void
    {
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create Account', [
            'admin_user[name]' => 'Copy Cat',
            'admin_user[email]' => 'juan@test.vj',
            'admin_user[role]' => User::ROLE_STAFF,
            'admin_user[plainPassword][password]' => 'short',
            'admin_user[plainPassword][password_confirmation]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.admin-editor', 'An account already uses that email address.');
        self::assertSelectorExists('.admin-editor .alert-danger + .alert-danger');
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['name' => 'Copy Cat']));
    }

    public function testRejectsAnUnknownRole(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Create Account')->form();
        $values = $form->getPhpValues();
        $values['admin_user'] = ['name' => 'Bad Role', 'email' => 'new.staff@test.vj', 'role' => 'superuser', 'plainPassword' => ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]] + $values['admin_user'];
        $this->client->request('POST', '/admin/users/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.admin-editor', 'Choose a valid role.');
        self::assertNull($this->user('new.staff@test.vj'));
    }

    public function testEditsNameAndEmail(): void
    {
        $this->client->request('GET', \sprintf('/admin/users/%d/edit', $this->juan->getId()));
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('admin_user[email]', 'juan@test.vj');
        // role and status stay on the list, where the last-admin rules apply
        self::assertSelectorNotExists('[name="admin_user[role]"]');

        $this->client->submitForm('Save Changes', [
            'admin_user[name]' => 'Juan P. Dela Cruz',
            'admin_user[email]' => 'Juan.New@test.vj',
        ]);

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Account details updated for Juan P. Dela Cruz.');

        $juan = $this->em()->find(User::class, $this->juan->getId());
        self::assertSame('Juan P. Dela Cruz', $juan->getName());
        self::assertSame('juan.new@test.vj', $juan->getEmail());
        self::assertTrue($this->hasher()->isPasswordValid($juan, self::PASSWORD), 'editing details keeps the password');
    }

    public function testEditRejectsAnEmailAlreadyInUse(): void
    {
        $this->client->request('GET', \sprintf('/admin/users/%d/edit', $this->juan->getId()));
        $this->client->submitForm('Save Changes', ['admin_user[email]' => 'maria@test.vj']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'That email is already registered.');
        self::assertSame('juan@test.vj', $this->em()->find(User::class, $this->juan->getId())->getEmail());
    }

    public function testEditingYourOwnEmailKeepsYouSignedIn(): void
    {
        $this->client->request('GET', \sprintf('/admin/users/%d/edit', $this->admin->getId()));
        $this->client->submitForm('Save Changes', ['admin_user[email]' => 'new.admin@test.vj']);
        self::assertResponseRedirects('/admin/users', 303);

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-table', 'new.admin@test.vj • You');

        // put the shared test admin back for the other tests
        $this->em()->find(User::class, $this->admin->getId())->setEmail(self::ADMIN_EMAIL);
        $this->em()->flush();
    }

    public function testResetsAPassword(): void
    {
        $this->juan->startPasswordReset(hash('sha256', 'emailed-token'), new \DateTimeImmutable('+1 hour'));
        $this->em()->flush();

        $this->client->request('GET', \sprintf('/admin/users/%d/password', $this->juan->getId()));
        self::assertSelectorTextContains('h1', 'Reset password for Juan Dela Cruz');
        $this->client->submitForm('Set New Password', [
            'reset_password[plainPassword][password]' => self::NEW_PASSWORD,
            'reset_password[plainPassword][password_confirmation]' => self::NEW_PASSWORD,
        ]);

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Password reset for Juan Dela Cruz.');

        $this->em()->clear();
        $juan = $this->em()->find(User::class, $this->juan->getId());
        self::assertTrue($this->hasher()->isPasswordValid($juan, self::NEW_PASSWORD));
        self::assertFalse($this->hasher()->isPasswordValid($juan, self::PASSWORD));
        self::assertNull($juan->getResetTokenHash(), 'the emailed reset link stops working');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'user_password_reset', 'entityId' => $juan->getId()]));
    }

    public function testPasswordResetNeedsAMatchingStrongPassword(): void
    {
        $this->client->request('GET', \sprintf('/admin/users/%d/password', $this->juan->getId()));
        $this->client->submitForm('Set New Password', [
            'reset_password[plainPassword][password]' => self::NEW_PASSWORD,
            'reset_password[plainPassword][password_confirmation]' => 'Different2026',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'The password confirmation does not match.');
        $this->em()->clear();
        self::assertTrue($this->hasher()->isPasswordValid($this->em()->find(User::class, $this->juan->getId()), self::PASSWORD));
    }

    public function testDeletingAsksForConfirmationFirst(): void
    {
        $this->client->request('GET', '/admin/users?q=juan@test.vj');
        $this->client->clickLink('Delete');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Delete this account?');
        self::assertSelectorTextContains('.admin-editor', 'This cannot be undone.');
        self::assertNotNull($this->user('juan@test.vj'), 'the prompt deletes nothing');

        $this->client->submitForm('Yes, Delete Account');

        self::assertResponseRedirects('/admin/users', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Account deleted for Juan Dela Cruz.');
        self::assertNull($this->user('juan@test.vj'));
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'user_deleted', 'entityId' => $this->juan->getId()]));
    }

    public function testDeleteNeedsAValidToken(): void
    {
        $this->client->request('POST', \sprintf('/admin/users/%d/delete', $this->juan->getId()), ['_token' => 'wrong']);

        self::assertResponseRedirects(\sprintf('/admin/users/%d/delete', $this->juan->getId()), 303);
        self::assertNotNull($this->user('juan@test.vj'));
    }

    public function testCannotDeleteYourOwnAccount(): void
    {
        $url = \sprintf('/admin/users/%d/delete', $this->admin->getId());

        $this->client->request('GET', $url);
        self::assertSelectorTextContains('h1', 'This account cannot be deleted');
        self::assertSelectorTextContains('.alert-warning', 'You cannot delete your own account.');
        self::assertSelectorNotExists('.admin-editor button[type="submit"]');

        // the POST path enforces the same rule, whatever the page offered
        $admin = $this->em()->find(User::class, $this->admin->getId());
        try {
            static::getContainer()->get(AdminUserManager::class)->delete($admin, $admin);
            self::fail('deleting your own account must be refused');
        } catch (\DomainException $error) {
            self::assertSame('You cannot delete your own account.', $error->getMessage());
        }
        self::assertNotNull($this->user(self::ADMIN_EMAIL));
    }

    public function testAccountsWithHistoryMustBeDeactivatedInstead(): void
    {
        $staff = $this->createUser('new.staff@test.vj', User::ROLE_STAFF, name: 'Sam Staff');
        $vehicle = $this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => 'toyota-hiace']);
        $this->em()->persist((new MaintenanceRecord())
            ->setVehicle($vehicle)
            ->setTitle('Oil change')
            ->setDescription('Routine service work.')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setCreatedBy($staff));
        $this->em()->flush();

        $this->client->request('GET', \sprintf('/admin/users/%d/delete', $staff->getId()));

        self::assertSelectorTextContains('.alert-warning', 'Set it to inactive instead.');
        self::assertSelectorNotExists('.admin-editor button[type="submit"]');
        self::assertNotNull($this->user('new.staff@test.vj'));
    }

    public function testNonAdminsCannotManageAccounts(): void
    {
        foreach ([User::ROLE_STAFF, User::ROLE_CUSTOMER] as $role) {
            $this->client->loginUser($this->createUser('new.staff@test.vj', $role));

            foreach (['/admin/users/new', \sprintf('/admin/users/%d/edit', $this->juan->getId()), \sprintf('/admin/users/%d/password', $this->juan->getId()), \sprintf('/admin/users/%d/delete', $this->juan->getId())] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseStatusCodeSame(403, "$role GET $url");
            }

            $this->client->request('POST', \sprintf('/admin/users/%d/delete', $this->juan->getId()), ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403, "$role POST delete");
        }

        self::assertNotNull($this->user('juan@test.vj'));
    }

    private function user(string $email): ?User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
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
