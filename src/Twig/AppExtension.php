<?php

namespace App\Twig;

use App\Service\VehicleImageStorage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig ports of the v15 presentation helpers (money(), status_class(),
 * humanize_label()) plus page_url(), which maps a v15 page name such as
 * "vehicles" or "admin-bookings" to its Symfony route.
 */
final class AppExtension
{
    // v15 page name => Symfony route name. Pages that are not built yet fall back to app_coming_soon.
    private const PAGE_ROUTES = [
        'index' => 'app_home',
        'about' => 'app_about',
        'services' => 'app_services',
        'contact' => 'app_contact',
        'faq' => 'app_faq',
        'terms' => 'app_terms',
        'privacy' => 'app_privacy',
        'vehicles' => 'app_vehicle_index',
        'vehicle-details' => 'app_vehicle_show',
        'login' => 'app_login',
        'register' => 'app_register',
        'logout' => 'app_logout',
        'account' => 'app_account',
        'setup' => 'app_setup',
        'admin' => 'app_admin',
        'admin-vehicles' => 'app_admin_vehicles',
        'admin-addons' => 'app_admin_addons',
        'admin-users' => 'app_admin_users',
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly VehicleImageStorage $vehicleImages,
    ) {
    }

    /**
     * Public path of a fleet photo, or the v15 placeholder when the file is missing.
     */
    #[AsTwigFunction('vehicle_image')]
    public function vehicleImage(?string $filename): string
    {
        return '/'.$this->vehicleImages->publicPath($filename);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[AsTwigFunction('page_url')]
    public function pageUrl(string $page, array $parameters = []): string
    {
        $route = self::PAGE_ROUTES[$page] ?? null;

        if (null !== $route) {
            try {
                return $this->urlGenerator->generate($route, $parameters);
            } catch (RouteNotFoundException) {
                // Route belongs to a later project step; fall through to the placeholder page.
            }
        }

        return $this->urlGenerator->generate('app_coming_soon', ['feature' => $page]);
    }

    #[AsTwigFilter('money')]
    public function money(int|float|null $amount): string
    {
        return '₱'.number_format((float) $amount, 0);
    }

    #[AsTwigFilter('humanize_label')]
    public function humanizeLabel(?string $value): string
    {
        $label = trim(str_replace(['_', '-'], ' ', (string) $value));

        return ucwords(preg_replace('/\s+/', ' ', $label) ?? $label);
    }

    #[AsTwigFilter('status_class')]
    public function statusClass(?string $status): string
    {
        return match ($status) {
            'confirmed', 'completed', 'converted', 'approved', 'activated', 'available', 'read', 'closed', 'paid' => 'success',
            'cancelled', 'rejected', 'inactive', 'failed', 'damaged', 'unavailable', 'expired' => 'danger',
            'pending', 'new', 'reserved', 'scheduled', 'ready' => 'warning',
            'active', 'returned', 'rented', 'in_progress', 'refunded', 'processing' => 'info',
            default => 'secondary',
        };
    }
}
