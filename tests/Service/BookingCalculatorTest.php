<?php

namespace App\Tests\Service;

use App\Entity\Promo;
use App\Form\Model\BookingRequest;
use App\Service\Booking\BookingCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookingCalculatorTest extends KernelTestCase
{
    private BookingCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->calculator = static::getContainer()->get(BookingCalculator::class);
        $this->em()->createQuery('DELETE FROM '.Promo::class.' p WHERE p.code <> :seed')->execute(['seed' => 'VJ10']);
    }

    public function testPricesAV15Booking(): void
    {
        // Toyota Fortuner: ₱6,200/day, ₱5,000 deposit; child seat ₱250 each, GPS ₱150
        $quote = $this->calculator->quote($this->request(
            pickupMethod: 'Vehicle delivery',
            deliveryAddress: '12 Rizal Street, Dumaguete City',
            addons: ['child-seat', 'gps'],
            childSeats: '2',
            promo: ' vj10 ',
        ));

        self::assertSame(3, $quote->days);
        self::assertSame(18600, $quote->subtotal);
        self::assertSame(650, $quote->addonsTotal);
        self::assertSame(800, $quote->deliveryFee);
        self::assertSame(1860, $quote->discount, '10% of the rental subtotal only');
        self::assertSame(18190, $quote->total());
        self::assertSame(5000, $quote->deposit());
        self::assertSame('VJ10', $quote->promoCode());
        self::assertSame([2, 1], array_map(static fn ($line) => $line->quantity, $quote->addons));
    }

    public function testEveryStartedDayIsBilledAndBranchPickupHasNoAddress(): void
    {
        $quote = $this->calculator->quote($this->request(returnOffset: 3, returnTime: '10:00', deliveryAddress: 'ignored for branch pickup'));

        self::assertSame(2, $quote->days, '25 hours bill as 2 days');
        self::assertSame('', $quote->deliveryAddress);
        self::assertSame(0, $quote->deliveryFee);
    }

    public function testFixedPromotionNeverExceedsTheSubtotal(): void
    {
        $promo = (new Promo())->setCode('BIG')->setDescription('Huge fixed discount')->setDiscountType('fixed')->setDiscountValue(100000);
        $this->em()->persist($promo);
        $this->em()->flush();

        $quote = $this->calculator->quote($this->request(promo: 'BIG'));

        self::assertSame($quote->subtotal, $quote->discount);
        self::assertSame(0, $quote->total());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'unknown vehicle' => [['vehicle' => 'flying-car'], 'Choose an active vehicle.'];
        yield 'bad date' => [['pickupDate' => '2026-02-30'], 'Choose valid pick-up and return dates and times.'];
        yield 'bad time' => [['pickupTime' => '25:00'], 'Choose valid pick-up and return dates and times.'];
        yield 'past pickup' => [['pickupOffset' => 0, 'pickupTime' => '00:00'], 'Pick-up must be at least one hour from now.'];
        yield 'return before pickup' => [['returnOffset' => 2, 'returnTime' => '08:00'], 'Return must be later than pick-up.'];
        yield 'over 30 days' => [['returnOffset' => 40], 'Online bookings can cover up to 30 rental days. Contact support for longer rentals.'];
        yield 'pickup method' => [['pickupMethod' => 'Drone drop'], 'Choose a valid pickup method.'];
        yield 'branch' => [['location' => 'Cebu Branch'], 'Choose a valid branch.'];
        yield 'delivery address' => [['pickupMethod' => 'Vehicle delivery', 'deliveryAddress' => 'Short'], 'Enter a complete delivery address.'];
        yield 'unknown add-on' => [['addons' => ['gps', 'jetpack']], 'One or more selected add-ons are unavailable.'];
        yield 'child seats' => [['addons' => ['child-seat'], 'childSeats' => '5'], 'Choose between 1 and 4 child safety seats.'];
        yield 'promo' => [['promo' => 'NOPE'], 'The promotion code is invalid, inactive, expired, or fully used.'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidInputWithV15Messages(array $overrides, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));

        $this->calculator->quote($this->request(...$overrides));
    }

    /**
     * @param list<string> $addons
     */
    private function request(
        string $vehicle = 'toyota-fortuner',
        int $pickupOffset = 2,
        string $pickupTime = '09:00',
        int $returnOffset = 5,
        string $returnTime = '09:00',
        ?string $pickupDate = null,
        string $pickupMethod = 'Branch pickup',
        string $location = 'Bais City Branch',
        string $deliveryAddress = '',
        array $addons = [],
        string $childSeats = '1',
        string $promo = '',
    ): BookingRequest {
        $request = new BookingRequest();
        $request->vehicle = $vehicle;
        $request->pickupDate = $pickupDate ?? (new \DateTimeImmutable("+$pickupOffset days"))->format('Y-m-d');
        $request->pickupTime = $pickupTime;
        $request->returnDate = (new \DateTimeImmutable("+$returnOffset days"))->format('Y-m-d');
        $request->returnTime = $returnTime;
        $request->pickupMethod = $pickupMethod;
        $request->location = $location;
        $request->deliveryAddress = $deliveryAddress;
        $request->addons = $addons;
        $request->childSeats = $childSeats;
        $request->promo = $promo;

        return $request;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
