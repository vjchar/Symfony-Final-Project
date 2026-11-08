<?php

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Entity\PasswordReset;
use App\Entity\User;
use App\EventListener\SecurityHeadersListener;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Request-level protections: trusted hosts, the debug-mode guard, the local
 * reset link, browser security headers and ending deactivated sessions.
 */
final class HardeningTest extends WebTestCase
{
    use LogsInUsers;

    private const CUSTOMER_EMAIL = 'juan@test.vj';
    private const CONTACT_EMAIL = 'limit-test@test.vj';
    // TEST-NET-3: listed in DEBUG_ALLOWED_IPS by .env.test, but not local
    private const ALLOWED_REMOTE_IP = '203.0.113.5';
    // TEST-NET-2: not allowed anywhere
    private const BLOCKED_REMOTE_IP = '198.51.100.7';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        static::getContainer()->get('doctrine')->getManager()
            ->createQuery('DELETE FROM '.PasswordReset::class)->execute();
    }

    public function testRequestsForAnUntrustedHostAreRefused(): void
    {
        $this->client->request('GET', '/login', server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testPasswordResetEmailUsesTheTrustedHost(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);
        $this->requestReset(self::CUSTOMER_EMAIL, ['HTTP_HOST' => 'evil.example']);

        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }

    public function testDebugBuildRefusesAddressesThatAreNotAllowed(): void
    {
        $this->client->request('GET', '/login', server: ['REMOTE_ADDR' => self::BLOCKED_REMOTE_IP]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('This development build only answers local requests.', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/_profiler', server: ['REMOTE_ADDR' => self::BLOCKED_REMOTE_IP]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testResetLinkIsOnlyShownToThisMachine(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL);
        $this->requestReset(self::CUSTOMER_EMAIL, ['REMOTE_ADDR' => self::ALLOWED_REMOTE_IP]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-info', 'If that account exists, a password-reset link has been prepared.');
        self::assertSelectorNotExists('.local-reset-link');
        // the account owner still gets the link by email
        self::assertEmailCount(1);
    }

    public function testResponsesCarryBrowserSecurityHeaders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        foreach (SecurityHeadersListener::HEADERS as $name => $value) {
            self::assertResponseHeaderSame($name, $value);
        }
    }

    public function testDeactivatingAnAccountEndsItsSession(): void
    {
        $this->client->loginUser($this->createUser(self::CUSTOMER_EMAIL));
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->createUser(self::CUSTOMER_EMAIL, status: User::STATUS_INACTIVE);
        $this->client->request('GET', '/account');

        self::assertResponseRedirects('/login?return_to=/account');
    }

    public function testActiveSessionsSurviveUnrelatedChanges(): void
    {
        $user = $this->createUser(self::CUSTOMER_EMAIL);
        $this->client->loginUser($user);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->find(User::class, $user->getId())->setName('Juan Updated');
        $em->flush();

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    public function testSignUpAttemptsAreLimitedPerNetwork(): void
    {
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->client->request('GET', '/register');
            // an invalid sign-up still counts, so the duplicate-email answer can't be farmed
            $this->client->submitForm('Create Account', ['registration_form[name]' => '']);
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.alert-danger', 'Too many sign-up attempts from your network. Please try again later.');
    }

    public function testContactMessagesAreLimitedPerNetwork(): void
    {
        $this->removeContactMessages();
        try {
            for ($attempt = 1; $attempt <= 6; ++$attempt) {
                $this->client->request('GET', '/contact');
                $this->client->submitForm('Send Message', [
                    'contact[name]' => 'Juan Dela Cruz',
                    'contact[email]' => self::CONTACT_EMAIL,
                    'contact[subject]' => 'Vehicle information',
                    'contact[message]' => 'Is the Toyota Hiace available for a family trip next month?',
                ]);
            }

            self::assertResponseStatusCodeSame(429);
            self::assertSelectorTextContains('.alert-danger', 'Too many messages from your network.');
            self::assertSame(5, static::getContainer()->get('doctrine')->getRepository(ContactMessage::class)->count(['email' => self::CONTACT_EMAIL]));
        } finally {
            $this->removeContactMessages();
        }
    }

    public function testResetRequestsAreLimitedPerNetwork(): void
    {
        // different addresses each time, so only the per-network limit applies
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->requestReset(sprintf('nobody-%d@test.vj', $attempt));
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.alert-info', 'Too many reset requests from your network.');
    }

    private function removeContactMessages(): void
    {
        static::getContainer()->get('doctrine')->getManager()
            ->createQuery('DELETE FROM '.ContactMessage::class.' m WHERE m.email = :email')
            ->execute(['email' => self::CONTACT_EMAIL]);
    }

    /**
     * @param array<string, string> $server
     */
    private function requestReset(string $email, array $server = []): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send Reset Link')->form(['forgot_password[email]' => $email]);
        $this->client->request('POST', '/forgot-password', $form->getPhpValues(), server: $server);
    }
}
