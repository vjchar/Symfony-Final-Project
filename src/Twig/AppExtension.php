<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Routing\PageUrlGenerator;
use App\Service\VehicleImageStorage;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig ports of the v15 presentation helpers (money(), status_class(),
 * humanize_label()) plus page_url() (see PageUrlGenerator).
 */
final class AppExtension
{
    public function __construct(
        private readonly PageUrlGenerator $pageUrls,
        private readonly VehicleImageStorage $vehicleImages,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * v15 admin_exists(): drives the "Administrator setup is required" notice,
     * shown only while /setup is open (no administrator account at all).
     */
    #[AsTwigFunction('admin_exists')]
    public function adminExists(): bool
    {
        return $this->userRepository->count(['role' => User::ROLE_ADMIN]) > 0;
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
        return $this->pageUrls->generate($page, $parameters);
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
            'confirmed', 'completed', 'converted', 'approved', 'activated', 'available', 'read', 'closed', 'paid', 'settled' => 'success',
            'cancelled', 'rejected', 'inactive', 'failed', 'damaged', 'unavailable', 'expired', 'balance_due' => 'danger',
            'pending', 'new', 'reserved', 'scheduled', 'ready', 'refund_pending' => 'warning',
            'active', 'returned', 'rented', 'in_progress', 'refunded', 'processing', 'finalized' => 'info',
            default => 'secondary',
        };
    }
}
