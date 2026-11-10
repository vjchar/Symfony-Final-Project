<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * v15 write_audit(): records who did what, with a hashed client IP. A failed
 * write is logged and never breaks the action being audited.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(string $action, string $entityType, ?int $entityId = null, array $metadata = [], ?User $actor = null): void
    {
        try {
            $user = $actor ?? $this->security->getUser();
            $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli';

            $this->entityManager->persist(new AuditLog(
                $user instanceof User ? $user : null,
                $action,
                $entityType,
                $entityId,
                $metadata,
                hash('sha256', $ip.$this->secret),
            ));
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            $this->logger->error('Audit write failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }
}
