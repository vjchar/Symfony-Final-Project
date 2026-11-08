<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * v15 request_password_reset(), password_reset_record() and complete_password_reset():
 * one-hour links, at most one request per account every 15 minutes.
 */
final class PasswordResetManager
{
    public const LIFETIME = '+1 hour';
    public const REQUEST_INTERVAL = '-15 minutes';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $mailFrom,
    ) {
    }

    /**
     * Returns the reset link when one was prepared, null otherwise. Callers must
     * not reveal which happened, so unknown emails can't be told apart.
     */
    public function request(string $email): ?string
    {
        $user = $this->users->findOneBy(['email' => strtolower(trim($email)), 'status' => User::STATUS_ACTIVE]);
        if (null === $user || $user->hasPasswordResetRequestSince(new \DateTimeImmutable(self::REQUEST_INTERVAL))) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $user->startPasswordReset(hash('sha256', $token), new \DateTimeImmutable(self::LIFETIME));
        $this->entityManager->flush();

        $link = $this->urlGenerator->generate('app_reset_password', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->sendLink($user, $link);

        return $link;
    }

    /**
     * The account whose open reset matches this token, if it is still usable.
     */
    public function find(string $token): ?User
    {
        $token = trim($token);

        return '' === $token ? null : $this->users->findByUsableResetToken(hash('sha256', $token), new \DateTimeImmutable());
    }

    public function complete(User $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->clearPasswordReset();
        $this->entityManager->flush();
    }

    private function sendLink(User $user, string $link): void
    {
        $email = (new Email())
            ->from(new Address($this->mailFrom, 'VJ Car Rental'))
            ->to((string) $user->getEmail())
            ->subject('Reset your VJ Car Rental password')
            ->text("A password reset was requested for your account.\n\n"
                ."Reset it here: {$link}\n\n"
                .'This link expires in one hour. If you did not request it, ignore this email.');

        try {
            $this->mailer->send($email);
        } catch (\Throwable $error) {
            // like v15 mail(): a mail failure must not tell the visitor anything different
            $this->logger->error('Password reset email failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }
}
