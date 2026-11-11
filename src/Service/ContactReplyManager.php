<?php

namespace App\Service;

use App\Entity\ContactMessage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Answers a contact enquiry from the admin inbox: the reply is emailed to
 * the sender (quoting their message) and kept on the enquiry.
 */
final class ContactReplyManager
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $mailFrom,
    ) {
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    public function reply(ContactMessage $message, string $reply, User $admin): void
    {
        $reply = trim($reply);
        if (mb_strlen($reply) < 5 || mb_strlen($reply) > 5000) {
            throw new \InvalidArgumentException('Write a reply between 5 and 5,000 characters.');
        }

        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->mailFrom, 'VJ Car Rental'))
                ->replyTo($this->mailFrom)
                ->to(new Address((string) $message->getEmail(), (string) $message->getName()))
                ->subject('Re: '.$message->getSubject().' | VJ Car Rental')
                ->text(sprintf(
                    "Hi %s,\n\n%s\n\n— %s, VJ Car Rental\n\n----- Your message of %s -----\n%s\n",
                    $message->getName(),
                    $reply,
                    $admin->getName(),
                    $message->getCreatedAt()->format('M j, Y g:i A'),
                    $message->getMessage(),
                )));
        } catch (TransportExceptionInterface) {
            throw new \DomainException('The reply could not be sent. Check the mail settings and try again.');
        }

        $message->recordReply($reply, $admin);
        $this->entityManager->flush();
        $this->auditLogger->log('contact_message_replied', 'contact_message', $message->getId(), [], $admin);
    }
}
