<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\LogsInUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityTest extends WebTestCase
{
    use LogsInUsers;

    private const CUSTOMER_EMAIL = 'juan@test.vj';
    private const NEW_EMAIL = 'maria@test.vj';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->removeUser(self::NEW_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->removeUser(self::NEW_EMAIL);
        parent::tearDown();
    }

    /** @return iterable<array{string}> */
    public static function adminPages(): iterable
    {
        yield ['/admin'];
        yield ['/admin/vehicles'];
        yield ['/admin/addons'];
        yield ['/admin/users'];
        yield ['/coming-soon/admin-bookings'];
    }

    #[DataProvider('adminPages')]
    public function testAdminPagesSendGuestsToSignInWithReturnTo(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/login?return_to='.$url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Please sign in to continue.');
    }

    #[DataProvider('adminPages')]
    public function testCustomersGetV15AccessDenied(string $url): void
    {
        $this->client->loginUser($this->createUser(self::CUSTOMER_EMAIL));
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdministratorsCannotOpenTheCustomerAccount(): void
    {
        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/account');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSignInOpensDashboard(): void
    {
        $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');

        $this->login('ADMIN@test.vj', self::PASSWORD);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Administrator access granted.');
        self::assertSelectorTextContains('.admin-dashboard-hero h1', 'Vina');
        self::assertNotNull($this->users()->findOneBy(['email' => self::ADMIN_EMAIL])->getLastLoginAt());
    }

    public function testCustomerSignInFollowsReturnTo(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL, name: 'Juan Dela Cruz');

        $this->client->request('GET', '/login?return_to=/vehicles/porsche-911');
        $this->client->submitForm('Sign In', ['email' => self::CUSTOMER_EMAIL, 'password' => self::PASSWORD]);

        self::assertResponseRedirects('/vehicles/porsche-911');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Welcome back.');
        self::assertSelectorTextContains('.account-link', 'Juan');
        self::assertSelectorExists('.notification-link');
    }

    public function testExternalReturnToIsIgnored(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);

        $this->client->request('GET', '/login?return_to=//evil.example');
        $this->client->submitForm('Sign In', ['email' => self::CUSTOMER_EMAIL, 'password' => self::PASSWORD]);

        self::assertResponseRedirects('/account');
    }

    public function testWrongPasswordAndInactiveAccountShareOneMessage(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);
        $this->createUser('inactive@test.vj', User::ROLE_CUSTOMER, User::STATUS_INACTIVE);

        foreach ([[self::CUSTOMER_EMAIL, 'Wrong-pass1'], ['inactive@test.vj', self::PASSWORD]] as [$email, $password]) {
            $this->login($email, $password);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.auth-form .alert-danger', 'The email or password is incorrect.');
            self::assertInputValueSame('email', $email);
        }
    }

    public function testInvalidEmailMessage(): void
    {
        $this->login('not-an-email', 'x');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.auth-form .alert-danger', 'Enter a valid email address and password.');
    }

    public function testFiveFailuresLockSignInForFifteenMinutes(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);

        for ($i = 0; $i < 6; ++$i) {
            $this->login(self::CUSTOMER_EMAIL, 'Wrong-pass1');
        }
        $this->login(self::CUSTOMER_EMAIL, self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.auth-form .alert-danger', 'Too many unsuccessful attempts. Try again in 15 minutes.');
    }

    public function testLogoutRequiresPost(): void
    {
        $this->loginAsAdmin($this->client);

        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/admin');
        $this->client->submitForm('Sign out');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login?return_to=/admin');
    }

    public function testRegistrationCreatesCustomerSignsInAndAudits(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Create Account', [
            'registration_form[name]' => 'Maria Santos',
            'registration_form[email]' => 'Maria@Test.vj',
            'registration_form[phone]' => '0917 123 4567',
            'registration_form[plainPassword][password]' => 'Rental2026',
            'registration_form[plainPassword][password_confirmation]' => 'Rental2026',
            'registration_form[terms]' => '1',
        ]);

        self::assertResponseRedirects('/account');
        $user = $this->users()->findOneBy(['email' => self::NEW_EMAIL]);
        self::assertSame(User::ROLE_CUSTOMER, $user->getRole());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'Rental2026'));

        $audit = static::getContainer()->get('doctrine')->getRepository(AuditLog::class)->findOneBy(['action' => 'register', 'entityId' => $user->getId()]);
        self::assertSame(['role' => 'customer'], $audit->getMetadata());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Your account is ready.');
        self::assertSelectorTextContains('.account-hero h1', 'Hello, Maria');
    }

    public function testRegistrationUsesV15Rules(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);

        $this->client->request('GET', '/register');
        $this->client->submitForm('Create Account', [
            'registration_form[name]' => 'J',
            'registration_form[email]' => self::CUSTOMER_EMAIL,
            'registration_form[phone]' => '12',
            'registration_form[plainPassword][password]' => 'weak',
            'registration_form[plainPassword][password_confirmation]' => 'weak',
        ]);

        self::assertResponseStatusCodeSame(422);
        foreach ([
            'Enter your full name.',
            'An account already uses that email address.',
            'Enter a valid phone number.',
            'Password must contain at least 8 characters.',
            'Password must include an uppercase letter.',
            'Password must include a number.',
            'You must accept the terms and privacy policy.',
        ] as $message) {
            self::assertAnySelectorTextContains('.auth-form .alert-danger', $message);
        }
    }

    public function testPasswordConfirmationMustMatch(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Create Account', [
            'registration_form[name]' => 'Maria Santos',
            'registration_form[email]' => self::NEW_EMAIL,
            'registration_form[plainPassword][password]' => 'Rental2026',
            'registration_form[plainPassword][password_confirmation]' => 'Rental2027',
            'registration_form[terms]' => '1',
        ]);

        self::assertAnySelectorTextContains('.auth-form .alert-danger', 'The password confirmation does not match.');
    }

    public function testSetupIsClosedOnceAnAdministratorExists(): void
    {
        $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN);

        $this->client->request('GET', '/setup');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.setup-notice');
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign In', ['email' => $email, 'password' => $password]);
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function removeUser(string $email): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        if ($user = $em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $em->remove($user);
            $em->flush();
        }
    }
}
