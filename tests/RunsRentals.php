<?php

namespace App\Tests;

use App\Entity\Booking;
use App\Entity\CustomerDocument;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Takes a booking to "confirmed" the way the customer and the administrator
 * do it in the browser, then submits rental desk and refund forms from the
 * admin pages. Needs BuildsBookings, LogsInUsers and UploadsFiles.
 *
 * @property KernelBrowser $client
 * @property User          $customer
 * @property User          $admin
 */
trait RunsRentals
{
    private string $vehicleStatus;

    private function rememberVehicleStatus(): void
    {
        $this->vehicleStatus = $this->fortuner()->getAvailabilityStatus();
    }

    private function restoreVehicleStatus(): void
    {
        $this->em()->createQuery('UPDATE '.Vehicle::class.' v SET v.availabilityStatus = :status WHERE v.slug = :slug')
            ->execute(['status' => $this->vehicleStatus, 'slug' => 'toyota-fortuner']);
    }

    /**
     * Booked, ₱5,000 cash deposit and both documents submitted, then
     * approved and verified by the administrator.
     */
    private function confirmedBooking(): Booking
    {
        $this->client->loginUser($this->customer);
        $this->submitBooking($this->client);
        $booking = $this->bookings()[0];
        $this->pay($booking, 'deposit', 5000);
        foreach ([['drivers_license', 'N01-23-456789', $this->day(400)], ['government_id', 'PSN-1234-5678', '']] as [$type, $number, $expiry]) {
            $crawler = $this->client->request('GET', '/account/documents');
            $this->client->request('POST', '/account/documents', [
                '_token' => $crawler->filter('.operation-upload input[name="_token"]')->attr('value'),
                'document_type' => $type,
                'document_number' => $number,
                'expiry_date' => $expiry,
            ], ['document_file' => $this->png('id.png')]);
        }

        $this->client->loginUser($this->admin);
        $this->em()->clear();
        foreach ($this->em()->getRepository(CustomerDocument::class)->findAll() as $document) {
            $this->submit('/admin/documents?status=all', sprintf('form[action^="/admin/documents/%d/review"]', $document->getId()), ['status' => 'approved']);
        }
        $this->verifyPayments();
        self::assertSame('confirmed', $this->reload($booking)->getStatus());

        return $this->reload($booking);
    }

    private function pay(Booking $booking, string $type, int $amount): void
    {
        $this->client->loginUser($this->customer);
        $crawler = $this->client->request('GET', '/account/payments?reference='.$booking->getReference());
        $this->client->request('POST', '/account/payments', [
            '_token' => $crawler->filter('#paymentForm input[name="_token"]')->attr('value'),
            'reference' => $booking->getReference(),
            'payment_type' => $type,
            'method' => 'cash',
            'amount' => (string) $amount,
            'transaction_reference' => '',
        ]);
        self::assertResponseRedirects();
    }

    /**
     * The administrator verifies every payment still pending.
     */
    private function verifyPayments(): void
    {
        $this->client->loginUser($this->admin);
        $this->em()->clear();
        foreach ($this->em()->getRepository(Payment::class)->findBy(['status' => Payment::STATUS_PENDING]) as $payment) {
            $this->submit('/admin/payments', sprintf('form[action^="/admin/payments/%d/review"]', $payment->getId()), ['status' => 'paid']);
        }
    }

    /**
     * Opens the page, takes the CSRF token from the one form matching the
     * selector and posts the fields to its action.
     *
     * @param array<string, string> $fields
     */
    private function submit(string $page, string $formSelector, array $fields = []): void
    {
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->filter($formSelector);
        self::assertCount(1, $form, 'one form matches '.$formSelector.' on '.$page);
        $this->client->request('POST', (string) $form->attr('action'), $fields + ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);
    }

    private function deskAction(Booking $booking, string $action, array $fields = []): void
    {
        $this->client->loginUser($this->admin);
        $this->submit('/admin/rentals?reference='.$booking->getReference(), sprintf('form[action="/admin/rentals/%s/%s"]', $booking->getReference(), $action), $fields);
        self::assertResponseRedirects('/admin/rentals?reference='.$booking->getReference(), 303);
    }

    private function flash(): string
    {
        $crawler = $this->client->followRedirect();

        return trim($crawler->filter('.alert')->text(''));
    }

    private function reload(Booking $booking): Booking
    {
        $this->em()->clear();

        return $this->em()->find(Booking::class, $booking->getId());
    }

    private function fortuner(): Vehicle
    {
        $this->em()->clear();

        return $this->em()->getRepository(Vehicle::class)->findOneBy(['slug' => 'toyota-fortuner']);
    }
}
