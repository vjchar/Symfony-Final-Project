<?php

namespace App\Service;

use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Emails every in-app notification to its user. Sending waits until the
 * request, command or worker message is over, and skips any notification
 * whose row is not in the database (its transaction was rolled back), so
 * nobody is emailed about a change that did not happen.
 */
final class NotificationMailer
{
    /** @var list<Notification> */
    private array $queued = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $mailFrom,
    ) {
    }

    public function queue(Notification $notification): void
    {
        $this->queued[] = $notification;
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    public function flush(): void
    {
        [$queued, $this->queued] = [$this->queued, []];
        foreach ($queued as $notification) {
            $email = $notification->getUser()->getEmail();
            if (null === $notification->getId() || null === $email || '' === $email || !$this->saved($notification)) {
                continue;
            }

            try {
                $this->mailer->send((new Email())
                    ->from(new Address($this->mailFrom, 'VJ Car Rental'))
                    ->to($email)
                    ->subject($notification->getTitle().' | VJ Car Rental')
                    ->text(sprintf(
                        "%s\n\n%s\n\nView your updates: %s\n",
                        $notification->getTitle(),
                        $notification->getMessage(),
                        $this->urls->generate('app_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    )));
            } catch (TransportExceptionInterface $error) {
                // the in-app notification is already saved; a failed email must not break anything
                $this->logger->warning('Notification email could not be sent.', ['notification_id' => $notification->getId(), 'error' => $error->getMessage()]);
            }
        }
    }

    private function saved(Notification $notification): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM notifications WHERE id = ?', [$notification->getId()]);
    }
}
