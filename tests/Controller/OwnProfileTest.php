<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\LogsInUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Administrators and staff view and edit their own profile and password on
 * /account/profile, while the customer dashboard stays customers only.
 */
final class OwnProfileTest extends WebTestCase
{
    use LogsInUsers;

    private const NEW_PASSWORD = 'Fresh2026Pass';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * Other tests count active administrators (one notification each), so the
     * accounts made here must not outlive the test.
     */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
            ->setParameter('emails', ['profile.admin@test.vj', 'profile.staff@test.vj'])
            ->execute();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function accounts(): iterable
    {
        yield 'admin' => [User::ROLE_ADMIN, 'profile.admin@test.vj', '/admin'];
        yield 'staff' => [User::ROLE_STAFF, 'profile.staff@test.vj', '/staff/maintenance'];
    }

    #[DataProvider('accounts')]
    public function testViewsOwnProfile(string $role, string $email, string $home): void
    {
        $this->client->loginUser($this->createUser($email, $role, name: 'Pat Profile'));

        $this->client->request('GET', '/account/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'My profile');
        self::assertInputValueSame('profile[name]', 'Pat Profile');
        self::assertInputValueSame('profile[email]', $email);
        // back to their own home, not the customer dashboard they cannot open
        self::assertSelectorExists(\sprintf('form a[href="%s"]', $home));
        self::assertSelectorNotExists('form a[href="/account"]');
    }

    #[DataProvider('accounts')]
    public function testChangesOwnPasswordAndStaysSignedIn(string $role, string $email, string $home): void
    {
        $this->client->loginUser($this->createUser($email, $role, name: 'Pat Profile'));

        $this->submitProfile([
            'currentPassword' => self::PASSWORD,
            'newPassword' => ['new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD],
        ]);

        self::assertResponseRedirects('/account/profile', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Profile updated successfully.');

        $user = $this->reload($email);
        self::assertTrue($this->hasher()->isPasswordValid($user, self::NEW_PASSWORD));
        self::assertSame($role, $user->getRole(), 'the profile form cannot change the role');

        $this->client->request('GET', $home);
        self::assertResponseIsSuccessful();
    }

    #[DataProvider('accounts')]
    public function testPasswordChangeNeedsTheCurrentPassword(string $role, string $email, string $home): void
    {
        $this->client->loginUser($this->createUser($email, $role));

        $this->submitProfile([
            'currentPassword' => 'Wrong2026Pass',
            'newPassword' => ['new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Your current password is required for email or password changes.');
        self::assertTrue($this->hasher()->isPasswordValid($this->reload($email), self::PASSWORD));
    }

    public function testAdminNavigationLinksToProfile(): void
    {
        $this->loginAsAdmin($this->client);

        $this->client->request('GET', '/admin');

        self::assertSelectorExists('.admin-nav a[href="/account/profile"]');
    }

    public function testStaffWorkspaceLinksToProfile(): void
    {
        $this->client->loginUser($this->createUser('profile.staff@test.vj', User::ROLE_STAFF));

        $this->client->request('GET', '/staff/maintenance');

        self::assertSelectorExists('main a[href="/account/profile"]');
    }

    public function testCustomerDashboardIsStillCustomersOnly(): void
    {
        $this->loginAsAdmin($this->client);

        $this->client->request('GET', '/account');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitProfile(array $fields): void
    {
        $crawler = $this->client->request('GET', '/account/profile');
        $values = $crawler->selectButton('Save Profile')->form()->getPhpValues();
        $values['profile'] = array_replace_recursive($values['profile'], $fields);
        $this->client->request('POST', '/account/profile', $values);
    }

    private function reload(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
