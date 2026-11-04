<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Company and information pages from v15 pages/company/.
 */
final class PageController extends AbstractController
{
    // Kept in sync with CANCELLATION_REFUND_CUTOFF_HOURS in v15 includes/config.php.
    private const REFUND_CUTOFF_HOURS = 24;

    private const FAQS = [
        ['id' => 'requirements', 'question' => 'What do I need to rent a vehicle?', 'answer' => 'Prepare a valid driver’s license, an accepted government-issued ID, the refundable deposit, and the signed rental agreement. Final age, license-history, and payment requirements will be confirmed before release.'],
        ['id' => 'availability', 'question' => 'How is vehicle availability checked?', 'answer' => 'Choose your pick-up and return schedule on a vehicle page or during booking. Availability is checked against pending, confirmed, and active reservations before a new booking is accepted.'],
        ['id' => 'pricing', 'question' => 'What is included in the displayed daily rate?', 'answer' => 'Each vehicle page lists its specific mileage allowance, standard rental protection, sanitation, roadside assistance, and category-specific inclusions. Deposits and optional extras are shown separately.'],
        ['id' => 'deposit', 'question' => 'Is the security deposit included in the estimated total?', 'answer' => 'No. The estimate presents the refundable deposit separately. Refund timing and deductions will follow the signed rental agreement and documented return inspection.'],
        ['id' => 'fuel', 'question' => 'How should I return the fuel or charge level?', 'answer' => 'Return the vehicle at the fuel or charge level stated during release. EV customers receive a charging orientation and supplied cable when listed in the vehicle inclusions.'],
        ['id' => 'delivery', 'question' => 'Can the vehicle be delivered?', 'answer' => 'Yes. Choose vehicle delivery during booking, provide a complete service address, and review the delivery fee in the live estimate. Staff will confirm service coverage with the reservation.'],
        ['id' => 'additional-driver', 'question' => 'Can another person drive the rental?', 'answer' => 'An additional driver can be requested as an add-on. Every driver must meet the final renter requirements and be listed in the rental agreement before driving.'],
        ['id' => 'changes', 'question' => 'Can I reschedule or cancel?', 'answer' => 'Eligible pre-pickup bookings can submit a modification request for dates, fulfillment, add-ons, or an allowed vehicle change. Cancellation is also requested before pickup and reviewed by the rental team. Active rentals use the separate extension and early-return workflow.'],
        ['id' => 'rating', 'question' => 'Who can leave a vehicle rating?', 'answer' => 'Only the signed-in renter linked to a completed booking can submit one verified review for that trip. Reviews are moderated before they appear publicly.'],
        ['id' => 'roadside', 'question' => 'What happens during a breakdown or emergency?', 'answer' => 'Move to a safe location when possible, contact the support number supplied with the vehicle, and follow the rental agreement. Emergency services should be contacted first when anyone is in danger.'],
    ];

    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('page/about.html.twig');
    }

    #[Route('/services', name: 'app_services', methods: ['GET'])]
    public function services(): Response
    {
        return $this->render('page/services.html.twig', [
            'addons' => [],
        ]);
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('page/contact.html.twig');
    }

    #[Route('/faq', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('page/faq.html.twig', [
            'faqs' => self::FAQS,
        ]);
    }

    #[Route('/terms', name: 'app_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('page/terms.html.twig', [
            'refund_cutoff_hours' => self::REFUND_CUTOFF_HOURS,
        ]);
    }

    #[Route('/privacy', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('page/privacy.html.twig');
    }

    /**
     * Placeholder for v15 features that are built in later project steps (bookings, payments, ...).
     */
    #[Route('/coming-soon/{feature}', name: 'app_coming_soon', requirements: ['feature' => '[a-z0-9-]+'], methods: ['GET'])]
    public function comingSoon(string $feature): Response
    {
        return $this->render('page/coming_soon.html.twig', [
            'feature' => $feature,
        ]);
    }
}
