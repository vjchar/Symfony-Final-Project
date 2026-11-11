<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\ContactMessage;
use App\Entity\User;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MessageControllerTest extends WebTestCase
{
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em()->createQuery('DELETE FROM '.ContactMessage::class)->execute();
        $this->loginAsAdmin($this->client);
    }

    public function testShowsTheNewQueueByDefault(): void
    {
        $this->createMessage('Corporate rental', 'new', "Three vans for a company outing.\nPlease call me.", '0917 555 0100');
        $this->createMessage('Vehicle information', 'closed');

        $crawler = $this->client->request('GET', '/admin/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Contact enquiries');
        self::assertSelectorTextContains('.admin-filter-tabs a.active', 'New');
        self::assertCount(1, $crawler->filter('.admin-message-card'));
        self::assertSelectorTextContains('.admin-message-card h2', 'Corporate rental');
        self::assertSelectorTextContains('.admin-message-card .status-badge--warning', 'New');
        self::assertSelectorTextContains('.admin-message-card footer small', 'ana@test.vj • 0917 555 0100');
        self::assertStringContainsString('company outing.<br>', $crawler->filter('.admin-message-card > p')->html());
        self::assertSelectorExists('.admin-message-card a[href="mailto:ana@test.vj?subject=Re%3A%20Corporate%20rental"]');
    }

    public function testFiltersByStatus(): void
    {
        $this->createMessage('New booking', 'new');
        $this->createMessage('Vehicle information', 'read');
        $this->createMessage('Other inquiry', 'closed');

        $crawler = $this->client->request('GET', '/admin/messages?status=all');
        self::assertCount(3, $crawler->filter('.admin-message-card'));
        // newest first
        self::assertSame('Other inquiry', $crawler->filter('.admin-message-card h2')->first()->text());

        $crawler = $this->client->request('GET', '/admin/messages?status=read');
        self::assertCount(1, $crawler->filter('.admin-message-card'));
        self::assertSelectorTextContains('.admin-message-card h2', 'Vehicle information');

        // an unknown filter falls back to the new queue
        $this->client->request('GET', '/admin/messages?status=archived');
        self::assertSelectorTextContains('.admin-filter-tabs a.active', 'New');
        self::assertSelectorTextContains('.admin-message-card h2', 'New booking');
    }

    public function testEmptyQueue(): void
    {
        $this->client->request('GET', '/admin/messages?status=closed');

        self::assertSelectorTextContains('.empty-state h3', 'No messages here');
    }

    public function testMarksAMessageReadAndStaysOnTheQueue(): void
    {
        $message = $this->createMessage('New booking', 'new');

        $this->client->request('GET', '/admin/messages?status=new');
        $this->client->submitForm('Save', ['status' => 'read']);

        self::assertResponseRedirects('/admin/messages?status=new', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Enquiry status updated.');
        self::assertSelectorTextContains('.empty-state h3', 'No messages here');

        self::assertSame('read', $this->em()->find(ContactMessage::class, $message->getId())->getStatus());
        $audit = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'contact_message_updated', 'entityId' => $message->getId()]);
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        self::assertEquals(['from' => 'new', 'status' => 'read', 'admin_id' => $admin->getId()], $audit->getMetadata());
        self::assertSame(User::ROLE_ADMIN, $audit->getActorRole());
    }

    public function testRejectsAnInvalidStatusOrToken(): void
    {
        $message = $this->createMessage('New booking', 'new');

        $this->client->request('GET', '/admin/messages');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $this->client->request('POST', '/admin/messages?status=new', ['status' => 'deleted'] + $form->getValues());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid message status.');

        $this->client->request('POST', '/admin/messages', ['message_id' => $message->getId(), 'status' => 'closed', '_token' => 'wrong']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Your session expired.');

        self::assertSame('new', $this->em()->find(ContactMessage::class, $message->getId())->getStatus());
    }

    public function testNavAndDashboardLinkToTheInbox(): void
    {
        $this->createMessage('New booking', 'new');

        $this->client->request('GET', '/admin');

        self::assertSelectorExists('a[href="/admin/messages"]');
    }

    private function createMessage(string $subject, string $status, string $body = 'I would like to ask about your rental options.', string $phone = ''): ContactMessage
    {
        static $offset = 0;

        $message = (new ContactMessage())
            ->setName('Ana Reyes')
            ->setEmail('ana@test.vj')
            ->setPhone($phone)
            ->setSubject($subject)
            ->setMessage($body)
            ->setStatus($status);
        // distinct timestamps so newest-first ordering is testable
        (new \ReflectionProperty(ContactMessage::class, 'createdAt'))->setValue($message, new \DateTimeImmutable(sprintf('-%d minutes', 100 - ++$offset)));

        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
