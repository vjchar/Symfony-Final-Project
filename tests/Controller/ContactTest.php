<?php

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContactTest extends WebTestCase
{
    use LogsInUsers;

    private const EMAIL = 'contact-test@test.vj';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->removeMessages();
    }

    protected function tearDown(): void
    {
        $this->removeMessages();
        parent::tearDown();
    }

    public function testMessageIsStoredAsNew(): void
    {
        $this->client->request('GET', '/contact');
        $this->client->submitForm('Send Message', [
            'contact[name]' => 'Juan Dela Cruz',
            'contact[email]' => 'Contact-Test@Test.vj',
            'contact[subject]' => 'Vehicle information',
            'contact[message]' => 'Is the Toyota Hiace available for a family trip next month?',
        ]);

        self::assertResponseRedirects('/contact');
        $message = $this->messages()->findOneBy(['email' => self::EMAIL]);
        self::assertNotNull($message);
        self::assertSame('new', $message->getStatus());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Your message was received.');
    }

    public function testV15ValidationMessages(): void
    {
        $this->client->request('GET', '/contact');
        $this->client->submitForm('Send Message', [
            'contact[name]' => 'J',
            'contact[email]' => 'not-an-email',
            'contact[message]' => 'Too short',
        ]);

        self::assertResponseStatusCodeSame(422);
        foreach (['Enter your full name.', 'Enter a valid email address.', 'Choose a valid support topic.', 'Enter a message between 20 and 5,000 characters.'] as $error) {
            self::assertAnySelectorTextContains('.contact-form .alert-danger', $error);
        }
    }

    public function testHoneypotRejectsBots(): void
    {
        $this->client->request('GET', '/contact');
        $this->client->submitForm('Send Message', [
            'contact[website]' => 'http://spam.example',
            'contact[name]' => 'Spam Bot',
            'contact[email]' => self::EMAIL,
            'contact[subject]' => 'Other inquiry',
            'contact[message]' => 'Buy cheap things from my website right now please.',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->messages()->findOneBy(['email' => self::EMAIL]));
    }

    public function testDashboardCountsNewMessages(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist((new ContactMessage())->setName('Juan')->setEmail(self::EMAIL)->setSubject('Other inquiry')->setMessage('A message that is long enough.'));
        $em->flush();

        $this->loginAsAdmin($this->client);
        $crawler = $this->client->request('GET', '/admin');

        $stat = $crawler->filterXPath('//article[contains(@class, "dashboard-stat")][.//small[text()="New messages"]]');
        self::assertGreaterThanOrEqual(1, (int) $stat->filter('strong')->text());
        self::assertSelectorTextContains('.dashboard-status-panel', 'Luxury');
    }

    private function messages(): \Doctrine\Persistence\ObjectRepository
    {
        return static::getContainer()->get('doctrine')->getRepository(ContactMessage::class);
    }

    private function removeMessages(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(ContactMessage::class)->findBy(['email' => self::EMAIL]) as $message) {
            $em->remove($message);
        }
        $em->flush();
    }
}
