<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Debug mode (APP_ENV=dev) serves the web profiler and detailed error pages,
 * which show environment variables, database credentials and every request.
 * If a debug build is ever reachable from the network, only the addresses in
 * DEBUG_ALLOWED_IPS are answered; everyone else gets a plain 403.
 *
 * Production (APP_ENV=prod, APP_DEBUG=0) is not affected.
 */
final class DebugAccessListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire('%env(DEBUG_ALLOWED_IPS)%')]
        private readonly string $allowedIps,
    ) {
    }

    // before routing, the firewall and the profiler
    #[AsEventListener(priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->debug || !$event->isMainRequest()) {
            return;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $this->allowedIps))));
        $clientIp = (string) $event->getRequest()->getClientIp();

        if ('' === $clientIp || !IpUtils::checkIp($clientIp, $allowed)) {
            // a plain response: a debug exception page would expose what this guards
            $event->setResponse(new Response(
                'This development build only answers local requests.',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ));
        }
    }
}
