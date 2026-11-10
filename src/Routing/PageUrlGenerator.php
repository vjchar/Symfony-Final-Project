<?php

namespace App\Routing;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps a v15 page name such as "vehicles" or "admin-bookings" to its Symfony
 * route. Pages that are not built yet go to the "coming soon" page.
 */
final class PageUrlGenerator
{
    // v15 page name => Symfony route name
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
        'forgot-password' => 'app_forgot_password',
        'reset-password' => 'app_reset_password',
        'account' => 'app_account',
        'profile' => 'app_profile',
        'booking' => 'app_booking',
        'booking-confirmation' => 'app_booking_confirmation',
        'booking-view' => 'app_booking_show',
        'manage-booking' => 'app_manage_booking',
        'my-bookings' => 'app_my_bookings',
        'saved-bookings' => 'app_saved_bookings',
        'booking-modification' => 'app_booking_modification',
        'booking-cancellation' => 'app_booking_cancellation',
        'rental-adjustment' => 'app_rental_adjustment',
        'payments' => 'app_payments',
        'invoice' => 'app_invoice',
        'documents' => 'app_documents',
        'notifications' => 'app_notifications',
        'rate-trip' => 'app_rate_trip',
        'favorites' => 'app_favorites',
        'compare' => 'app_compare',
        'setup' => 'app_setup',
        'admin' => 'app_admin',
        'admin-bookings' => 'app_admin_bookings',
        'admin-rentals' => 'app_admin_rentals',
        'admin-vehicles' => 'app_admin_vehicles',
        'admin-addons' => 'app_admin_addons',
        'admin-users' => 'app_admin_users',
        'admin-messages' => 'app_admin_messages',
        'admin-promos' => 'app_admin_promos',
        'admin-maintenance' => 'app_admin_maintenance',
        'admin-payments' => 'app_admin_payments',
        'admin-documents' => 'app_admin_documents',
        'admin-reviews' => 'app_admin_reviews',
        'admin-calendar' => 'app_admin_calendar',
        'admin-reports' => 'app_admin_reports',
        'health' => 'app_admin_health',
        'admin-login' => 'app_admin_login',
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $page, array $parameters = []): string
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
}
