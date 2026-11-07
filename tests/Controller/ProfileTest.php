<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileTest extends WebTestCase
{
    use LogsInUsers;

    private const CUSTOMER_EMAIL = 'juan@test.vj';
    private const NEW_EMAIL = 'juan.dela.cruz@test.vj';
    private const OTHER_EMAIL = 'maria@test.vj';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->removeUser(self::NEW_EMAIL);
        $this->client->loginUser($this->createUser(self::CUSTOMER_EMAIL, name: 'Juan Dela Cruz'));
    }

    protected function tearDown(): void
    {
        $this->removeUser(self::NEW_EMAIL);
        parent::tearDown();
    }

    public function testShowsTheV15ProfileForm(): void
    {
        $this->client->request('GET', '/account/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'My profile');
        self::assertInputValueSame('profile[name]', 'Juan Dela Cruz');
        self::assertInputValueSame('profile[email]', self::CUSTOMER_EMAIL);
        self::assertSelectorExists('input[name="profile[newPassword][new_password_confirmation]"]');
        self::assertSelectorExists('a.btn[href="/account"]');
    }

    public function testAccountPageLinksToProfile(): void
    {
        $this->client->request('GET', '/account');

        self::assertSelectorExists('.account-hero__actions a[href="/account/profile"]');
    }

    public function testGuestsSignInFirstAndAdministratorsAreDenied(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/account/profile');
        self::assertResponseRedirects('/login?return_to=/account/profile');

        $this->client->loginUser($this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN));
        $this->client->request('GET', '/account/profile');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdatesContactDetailsWithoutThePassword(): void
    {
        $this->submit(['name' => 'Juan P. Dela Cruz', 'phone' => '0917 555 0100']);

        self::assertResponseRedirects('/account/profile', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Profile updated successfully.');
        self::assertInputValueSame('profile[name]', 'Juan P. Dela Cruz');
        self::assertInputValueSame('profile[phone]', '0917 555 0100');

        $user = $this->findUser(self::CUSTOMER_EMAIL);
        self::assertNotNull(static::getContainer()->get('doctrine')->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'profile_updated', 'entityId' => $user->getId()],
            ['id' => 'DESC'],
        ));
    }

    public function testEmailChangeNeedsTheCurrentPassword(): void
    {
        $this->submit(['email' => self::NEW_EMAIL]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Your current password is required for email or password changes.');
        self::assertInputValueSame('profile[email]', self::NEW_EMAIL);
        self::assertNull($this->findUser(self::NEW_EMAIL));

        $this->submit(['email' => self::NEW_EMAIL, 'currentPassword' => self::PASSWORD]);

        self::assertResponseRedirects('/account/profile', 303);
        $this->client->followRedirect();
        // still signed in under the new email
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('profile[email]', self::NEW_EMAIL);
    }

    public function testChangesThePasswordAndStaysSignedIn(): void
    {
        $this->submit(['currentPassword' => self::PASSWORD, 'newPassword' => ['new_password' => 'Journey2027', 'new_password_confirmation' => 'Journey2027']]);

        self::assertResponseRedirects('/account/profile', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->findUser(self::CUSTOMER_EMAIL), 'Journey2027'));
    }

    public function testRejectsWeakOrMismatchedNewPasswords(): void
    {
        $this->submit(['currentPassword' => self::PASSWORD, 'newPassword' => ['new_password' => 'weak', 'new_password_confirmation' => 'weak']]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.alert-danger', 'Password must contain at least 8 characters.');
        self::assertAnySelectorTextContains('.alert-danger', 'Password must include an uppercase letter.');

        $this->submit(['currentPassword' => self::PASSWORD, 'newPassword' => ['new_password' => 'Journey2027', 'new_password_confirmation' => 'Journey2028']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'The new password confirmation does not match.');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->findUser(self::CUSTOMER_EMAIL), self::PASSWORD));
    }

    public function testRejectsInvalidDetailsAndKeepsTheSavedName(): void
    {
        $this->createUser(self::OTHER_EMAIL);
        $this->submit(['name' => 'J', 'phone' => '12', 'email' => self::OTHER_EMAIL, 'currentPassword' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.alert-danger', 'Enter your full name.');
        self::assertAnySelectorTextContains('.alert-danger', 'Enter a valid phone number.');
        self::assertAnySelectorTextContains('.alert-danger', 'That email is already registered.');
        // the header greets the user by the saved name, not the rejected one
        self::assertSelectorTextSame('.account-link span', 'Juan');
        self::assertSame('Juan Dela Cruz', $this->findUser(self::CUSTOMER_EMAIL)->getName());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submit(array $fields): void
    {
        $crawler = $this->client->request('GET', '/account/profile');
        $form = $crawler->selectButton('Save Profile')->form();

        $values = $form->getPhpValues();
        $values['profile'] = array_replace_recursive($values['profile'], $fields);
        $this->client->request('POST', '/account/profile', $values);
    }

    private function findUser(string $email): ?User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => $email]);
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
