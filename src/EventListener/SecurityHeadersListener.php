<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The browser hardening headers v15 sent from its .htaccess: no framing by
 * other sites (clickjacking), no content-type sniffing, no full URLs leaked
 * to other origins, and no camera, microphone or location access.
 * A header a controller already set is left as it is.
 */
final class SecurityHeadersListener
{
    public const HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    #[AsEventListener]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
