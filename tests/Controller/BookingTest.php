<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Booking;
use App\Entity\Promo;
use App\Entity\User;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;

    private const CUSTOMER_EMAIL = 'juan@test.vj';

    private KernelBrowser $client;
    private User $customer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->customer = $this->createUser(self::CUSTOMER_EMAIL, name: 'Juan Dela Cruz');
        $this->client->loginUser($this->customer);
    }

    protected function tearDown(): void
    {
        // bookings point at users that other tests delete
        $this->clearBookings();
        parent::tearDown();
    }

    public function testGuestsSignInFirstAndAdministratorsAreDenied(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/booking');
        self::assertResponseRedirects('/login?return_to=/booking');

        $this->client->loginUser($this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN));
        $this->client->request('GET', '/booking');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBookThisVehicleOpensTheFormWithThatVehicle(): void
    {
        $crawler = $this->client->request('GET', '/vehicles/toyota-hiace');
        $this->client->click($crawler->selectLink('Book This Vehicle')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Build your rental');
        self::assertSelectorTextContains('#vehicleChoice option[selected]', 'Toyota Hiace');
        self::assertSelectorTextContains('#bookingVehiclePreviewTotal', '₱5,500');
        self::assertSelectorTextContains('.customer-summary', 'juan@test.vj');
        self::assertSelectorExists('#bookingForm[data-estimate-url="/booking/estimate"]');
        self::assertSelectorExists('input[name="addons[]"][value="child-seat"]');
        self::assertSelectorExists('select#childSeatQuantity[disabled]');
    }

    public function testRecalculateShowsTheServerEstimate(): void
    {
        $this->submitBooking($this->client, [
            'pickup_method' => 'Vehicle delivery',
            'delivery_address' => '12 Rizal Street, Dumaguete City',
            'addons' => ['child-seat', 'gps'],
            'addon_quantities' => ['child-seat' => '2'],
            'promo' => 'vj10',
        ], 'preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#bookingVehicleMessage.is-success', 'Available for the selected schedule.');
        self::assertSelectorTextContains('#bookingVehiclePreviewDays', '3');
        self::assertSelectorTextContains('#bookingVehiclePreviewSubtotal', '₱18,600');
        self::assertSelectorTextContains('#bookingVehiclePreviewAddons', '₱650');
        self::assertSelectorTextContains('#bookingVehiclePreviewDelivery', '₱800');
        self::assertSelectorTextContains('#bookingVehiclePreviewDiscount', '−₱1,860');
        self::assertSelectorTextContains('#bookingVehiclePreviewTotal', '₱18,190');
        // the form keeps what was entered
        self::assertSelectorExists('input[name="addons[]"][value="gps"][checked]');
        self::assertSelectorTextContains('#childSeatQuantity option[selected]', '2 seats');
        self::assertCount(0, $this->bookings(), 'a preview books nothing');
    }

    public function testInvalidInputShowsTheV15Message(): void
    {
        $this->submitBooking($this->client, ['location' => ''], 'preview');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Choose a valid branch.');
        self::assertSelectorTextContains('#bookingVehicleMessage.is-error', 'Choose a valid branch.');
    }

    public function testTermsMustBeAccepted(): void
    {
        $this->submitBooking($this->client, ['terms' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Review and accept the rental terms before continuing.');
        self::assertCount(0, $this->bookings());
    }

    public function testCreatesABookingAndShowsTheNextStep(): void
    {
        $this->submitBooking($this->client, [
            'addons' => ['child-seat'],
            'addon_quantities' => ['child-seat' => '2'],
            'promo' => 'VJ10',
            'special_requests' => 'Please include a phone mount.',
        ]);

        $booking = $this->bookings()[0];
        self::assertResponseRedirects('/booking/'.$booking->getReference().'/confirmation', 303);
        self::assertMatchesRegularExpression('/^VJ-\d{6}-[0-9A-F]{6}$/', $booking->getReference());
        self::assertSame('pending', $booking->getStatus());
        self::assertSame(18600 + 500 - 1860, $booking->getTotal());
        self::assertSame('Child safety seat', $booking->getAddons()->first()->getAddonName());
        self::assertSame(2, $booking->getAddons()->first()->getQuantity());
        self::assertSame(1, $this->em()->getRepository(Promo::class)->findOneBy(['code' => 'VJ10'])->getUsedCount(), 'the promotion is used up once');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'booking_created', 'entityId' => $booking->getId()]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Booking created. Continue with the next unfinished step shown below.');
        self::assertSelectorTextContains('.confirmation-banner h2', $booking->getReference());
        self::assertSelectorTextContains('.journey-next h2', 'Complete Payment');
        self::assertSelectorTextContains('.journey-progress__step.is-current', 'Payment');
        self::assertSelectorTextContains('.booking-summary-card', 'Child safety seat × 2');
        self::assertSelectorTextContains('.booking-summary-card', 'VJ10 discount');
        self::assertSelectorTextContains('.confirmation-details', '3 days');
    }

    public function testOverlappingDatesAreRefused(): void
    {
        $this->submitBooking($this->client);
        self::assertCount(1, $this->bookings());

        // the same Fortuner, overlapping by one day
        $this->submitBooking($this->client, ['pickup' => $this->day(4), 'return' => $this->day(7)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'This vehicle is already reserved during part of the selected schedule.');
        self::assertCount(1, $this->bookings());

        // back-to-back is fine
        $this->submitBooking($this->client, ['pickup' => $this->day(5), 'return' => $this->day(7)]);
        self::assertResponseRedirects();
        self::assertCount(2, $this->bookings());
    }

    public function testVehiclesInMaintenanceCannotBeBooked(): void
    {
        $vehicle = $this->em()->getRepository(\App\Entity\Vehicle::class)->findOneBy(['slug' => 'toyota-fortuner']);
        $vehicle->setAvailabilityStatus('maintenance');
        $this->em()->flush();

        try {
            $this->submitBooking($this->client, [], 'preview');
            self::assertSelectorTextContains('.alert-danger', 'This vehicle is already reserved during part of the selected schedule.');
        } finally {
            $vehicle = $this->em()->getRepository(\App\Entity\Vehicle::class)->findOneBy(['slug' => 'toyota-fortuner']);
            $vehicle->setAvailabilityStatus('available');
            $this->em()->flush();
        }
    }

    public function testEstimateEndpoint(): void
    {
        $crawler = $this->client->request('GET', '/booking');
        $token = $crawler->filter('#bookingForm input[name="_token"]')->attr('value');
        $fields = [
            '_token' => $token,
            'vehicle' => 'toyota-hiace',
            'pickup' => $this->day(1),
            'pickup_time' => '10:00',
            'return' => $this->day(3),
            'return_time' => '10:00',
            'pickup_method' => 'Branch pickup',
            'location' => 'Manjuyod Branch',
            'form_action' => 'preview',
        ];

        $this->client->request('POST', '/booking/estimate', $fields);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertSame(2, $json['estimate']['days']);
        self::assertSame('₱11,000', $json['estimate']['formatted']['total']);
        self::assertSame('₱0', $json['estimate']['formatted']['discount']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('POST', '/booking/estimate', ['location' => 'Nowhere'] + $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose a valid branch.', json_decode((string) $this->client->getResponse()->getContent(), true)['message']);

        $this->client->restart();
        $this->client->request('POST', '/booking/estimate', $fields);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Sign in with a customer account to calculate a booking estimate.', json_decode((string) $this->client->getResponse()->getContent(), true)['message']);
    }

    public function testBookingDetailsAreOnlyForTheirOwner(): void
    {
        $this->submitBooking($this->client, ['pickup_method' => 'Vehicle delivery', 'delivery_address' => '12 Rizal Street, Dumaguete City', 'special_requests' => "Line one\nLine two"]);
        $reference = $this->bookings()[0]->getReference();

        $this->client->request('GET', '/bookings/'.$reference);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-hero h1', 'Toyota Fortuner');
        self::assertSelectorTextContains('.page-hero .status-badge--warning', 'Pending');
        self::assertSelectorTextContains('.confirmation-details', '12 Rizal Street, Dumaguete City');
        self::assertSelectorTextContains('.booking-note', 'Line one');
        self::assertSelectorTextContains('.booking-summary-card', 'Current amount due');
        self::assertSelectorTextContains('.booking-management-panel h2', 'Documents and payment');

        $this->client->loginUser($this->createUser('maria@test.vj'));
        $this->client->request('GET', '/bookings/'.$reference);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Booking not found');

        $this->client->request('GET', '/booking/'.$reference.'/confirmation');
        self::assertResponseRedirects('/account/bookings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'That booking could not be found.');
    }

    public function testManageBookingFindsTheCustomersReference(): void
    {
        $this->submitBooking($this->client);
        $reference = $this->bookings()[0]->getReference();

        $this->client->request('GET', '/manage-booking');
        $this->client->submitForm('Open Booking', ['reference' => 'VJ-000000-NOPE00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'No accessible booking matches that reference.');

        $this->client->submitForm('Open Booking', ['reference' => ' '.strtolower($reference).' ']);
        self::assertResponseRedirects('/bookings/'.$reference, 303);
    }

    public function testMyBookingsListsAndFilters(): void
    {
        $this->submitBooking($this->client);
        $reference = $this->bookings()[0]->getReference();

        $crawler = $this->client->request('GET', '/account/bookings');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.booking-list-item'));
        self::assertSelectorTextContains('.booking-list-item small', $reference);
        self::assertSelectorTextContains('.booking-list-item__next', 'Complete Payment');
        self::assertSelectorTextContains('.booking-filter-tabs a.active', 'All');

        $this->client->request('GET', '/account/bookings?status=completed');
        self::assertSelectorTextContains('.empty-state h2', 'No Completed bookings found');
    }

    public function testAccountDashboardShowsTheNextStep(): void
    {
        $this->client->request('GET', '/account');
        self::assertSelectorTextContains('.dashboard-empty h3', 'No bookings yet');
        self::assertSelectorNotExists('.dashboard-priority-action');

        $this->submitBooking($this->client);
        $this->client->request('GET', '/account');

        self::assertSelectorTextContains('.dashboard-priority-action .section-kicker', 'Action required');
        self::assertSelectorTextContains('.dashboard-priority-action h2', 'Complete Payment');
        self::assertSelectorTextContains('.account-stats', '1Total bookings');
        self::assertSelectorTextContains('.booking-list-item', 'Next: Complete Payment');
    }

    public function testCancelledBookingsShowAClosedJourney(): void
    {
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->em()->createQuery('UPDATE '.Booking::class.' b SET b.status = :s WHERE b.id = :id')->execute(['s' => 'cancelled', 'id' => $booking->getId()]);

        $this->client->request('GET', '/bookings/'.$booking->getReference());

        self::assertSelectorTextContains('.journey-next h2', 'Booking Cancelled');
        self::assertSelectorNotExists('.journey-progress');
        self::assertSelectorTextNotContains('main', 'Request Booking Modification');
    }
}
