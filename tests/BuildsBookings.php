<?php

namespace App\Tests;

use App\Entity\Booking;
use App\Entity\BookingDraft;
use App\Entity\Payment;
use App\Entity\Promo;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fills the v15 booking form the way a browser submits it, and clears
 * bookings between tests.
 */
trait BuildsBookings
{
    private function clearBookings(): void
    {
        $em = $this->em();
        // booking_addons and booking_draft_addons go with them (ON DELETE CASCADE)
        $em->createQuery('DELETE FROM '.BookingDraft::class)->execute();
        $em->createQuery('DELETE FROM '.Booking::class)->execute();
        $em->createQuery('UPDATE '.Promo::class.' p SET p.usedCount = 0 WHERE p.code = :code')->execute(['code' => 'VJ10']);
    }

    /**
     * @param array<string, mixed> $fields v15 field names; they replace the defaults, and null leaves a field out like an unticked checkbox
     */
    private function submitBooking(KernelBrowser $client, array $fields = [], string $action = 'create_booking', string $url = '/booking'): void
    {
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('#bookingForm input[name="_token"]')->attr('value');

        $client->request('POST', $url, array_filter($fields + [
            '_token' => $token,
            'vehicle' => 'toyota-fortuner',
            'pickup' => $this->day(2),
            'pickup_time' => '09:00',
            'return' => $this->day(5),
            'return_time' => '09:00',
            'pickup_method' => 'Branch pickup',
            'location' => 'Dumaguete City Branch',
            'delivery_address' => '',
            'promo' => '',
            'special_requests' => '',
            'terms' => '1',
            'form_action' => $action,
        ], static fn (mixed $value): bool => null !== $value));
    }

    /**
     * Skips the rental desk: the booking is completed, its deposit and rental
     * total are verified cash payments, and its return settlement is settled
     * (nothing deducted, the deposit refunded), which is what a review needs.
     * Needs LogsInUsers for the administrator who records it.
     */
    private function completeWithSettlement(Booking $booking): void
    {
        $em = $this->em();
        $em->createQuery('UPDATE '.Booking::class.' b SET b.status = :completed WHERE b.id = :id')->execute(['completed' => 'completed', 'id' => $booking->getId()]);
        // an existing administrator is reused as is: saving it again would change its password hash and log it out
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL])
            ?? $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $booking = $em->find(Booking::class, $booking->getId());
        foreach ([Payment::TYPE_DEPOSIT => $booking->getDeposit(), Payment::TYPE_BALANCE => $booking->getTotal()] as $type => $amount) {
            $payment = new Payment($booking, $booking->getUser(), $type, Payment::METHOD_CASH, $amount, '', '');
            $payment->review(Payment::STATUS_PAID, '', $admin);
            $em->persist($payment);
        }
        $booking->finalizeSettlement(0, 0, 0, 0, 0, $admin);
        $em->flush();
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable(sprintf('+%d days', $offset)))->format('Y-m-d');
    }

    /** @return list<Booking> */
    private function bookings(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(Booking::class)->findAll();
    }

    /** @return list<BookingDraft> */
    private function drafts(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(BookingDraft::class)->findAll();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
