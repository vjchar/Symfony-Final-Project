<?php

namespace App\Tests\Controller;

use App\Entity\PasswordReset;
use App\Entity\User;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordResetTest extends WebTestCase
{
    use LogsInUsers;

    private const CUSTOMER_EMAIL = 'juan@test.vj';
    private const NEW_PASSWORD = 'Journey2027';
    private const PREPARED = 'If that account exists, a password-reset link has been prepared.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();
        static::getContainer()->get('doctrine')->getManager()
            ->createQuery('DELETE FROM '.PasswordReset::class)->execute();
        $this->createUser(self::CUSTOMER_EMAIL);
    }

    public function testSignInLinksToTheV15ResetPage(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->click($crawler->selectLink('Forgot password?')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reset your password');
        self::assertSelectorExists('a[href="/login"]');
    }

    public function testUnknownEmailGetsTheSameAnswerWithoutALink(): void
    {
        $this->requestReset('nobody@test.vj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-info', self::PREPARED);
        self::assertSelectorNotExists('.local-reset-link');
        self::assertEmailCount(0);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->requestReset('not-an-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-info', 'Enter a valid email address.');
    }

    public function testInactiveAccountsGetNoLink(): void
    {
        $this->createUser(self::CUSTOMER_EMAIL, status: User::STATUS_INACTIVE);
        $this->requestReset(self::CUSTOMER_EMAIL);

        self::assertSelectorTextContains('.alert-info', self::PREPARED);
        self::assertSelectorNotExists('.local-reset-link');
    }

    public function testEmailsALinkAndShowsItOutsideProduction(): void
    {
        $this->requestReset(' Juan@Test.vj ');

        self::assertSelectorTextContains('.alert-info', self::PREPARED);
        self::assertSelectorTextContains('.local-reset-link strong', 'Local development link');
        $link = $this->client->getCrawler()->selectLink('Open password reset')->link()->getUri();
        self::assertStringStartsWith('http://localhost/reset-password?token=', $link);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', self::CUSTOMER_EMAIL);
        self::assertEmailAddressContains($email, 'from', 'reservations@localhost');
        self::assertEmailHeaderSame($email, 'Subject', 'Reset your VJ Car Rental password');
        self::assertEmailTextBodyContains($email, $link);

        // only the hash is stored
        $reset = $this->resets()[0];
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);
        self::assertSame(hash('sha256', $query['token']), $reset->getTokenHash());
    }

    public function testOneRequestPerFifteenMinutes(): void
    {
        $this->requestReset(self::CUSTOMER_EMAIL);
        $this->requestReset(self::CUSTOMER_EMAIL);

        self::assertSelectorTextContains('.alert-info', self::PREPARED);
        self::assertSelectorNotExists('.local-reset-link');
        self::assertCount(1, $this->resets());
    }

    public function testResetsThePasswordOnceAndSignsInWithIt(): void
    {
        $link = $this->resetLink();

        $this->client->request('GET', $link);
        self::assertSelectorTextContains('h1', 'Choose a new password');

        $this->client->submitForm('Update Password', [
            'reset_password[plainPassword][password]' => 'weak',
            'reset_password[plainPassword][password_confirmation]' => 'weak',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.alert-danger', 'Password must include an uppercase letter.');

        $this->client->submitForm('Update Password', [
            'reset_password[plainPassword][password]' => self::NEW_PASSWORD,
            'reset_password[plainPassword][password_confirmation]' => 'Journey2028',
        ]);
        self::assertSelectorTextContains('.alert-danger', 'The password confirmation does not match.');

        $this->client->submitForm('Update Password', [
            'reset_password[plainPassword][password]' => self::NEW_PASSWORD,
            'reset_password[plainPassword][password_confirmation]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Password updated. You can now sign in.');

        $this->client->submitForm('Sign In', ['email' => self::CUSTOMER_EMAIL, 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/account');

        // the link is spent
        $this->client->request('GET', $link);
        self::assertSelectorTextContains('.alert-danger', 'This reset link is invalid or expired.');
        self::assertSelectorExists('a.btn[href="/forgot-password"]');
        self::assertSelectorNotExists('input[type="password"]');
    }

    public function testUnknownAndExpiredTokensAreRejected(): void
    {
        $this->client->request('GET', '/reset-password?token=not-a-real-token');
        self::assertSelectorTextContains('.alert-danger', 'This reset link is invalid or expired.');

        $this->client->request('GET', '/reset-password');
        self::assertSelectorTextContains('.alert-danger', 'This reset link is invalid or expired.');

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::CUSTOMER_EMAIL]);
        $em->persist(new PasswordReset($user, hash('sha256', 'expired-token'), new \DateTimeImmutable('-1 minute')));
        $em->flush();

        $this->client->request('POST', '/reset-password?token=expired-token', ['reset_password' => [
            'plainPassword' => ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD],
        ]]);
        self::assertSelectorTextContains('.alert-danger', 'This reset link is invalid or expired.');
    }

    private function requestReset(string $email): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send Reset Link', ['forgot_password[email]' => $email]);
    }

    private function resetLink(): string
    {
        $this->requestReset(self::CUSTOMER_EMAIL);

        return $this->client->getCrawler()->selectLink('Open password reset')->link()->getUri();
    }

    /** @return list<PasswordReset> */
    private function resets(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(PasswordReset::class)->findAll();
    }
}
