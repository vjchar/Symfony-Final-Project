<?php

namespace App\Tests\Repository;

use App\Entity\Vehicle;
use App\Form\Model\VehicleFilter;
use App\Repository\VehicleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VehicleRepositoryTest extends KernelTestCase
{
    private VehicleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(VehicleRepository::class);
    }

    public function testSearchCombinesFiltersAndPaginates(): void
    {
        $filter = new VehicleFilter();
        $filter->category = 'Pickup';
        $filter->transmission = 'Manual';

        $results = $this->repository->search($filter, 1, 12);

        self::assertGreaterThan(0, \count($results));
        foreach ($results as $vehicle) {
            self::assertSame('Pickup', $vehicle->getCategory());
            self::assertSame('Manual', $vehicle->getTransmission());
        }

        $all = $this->repository->search(new VehicleFilter(), 2, 12);
        self::assertSame(75, \count($all));
        self::assertCount(12, iterator_to_array($all));
    }

    public function testMinimumSeats(): void
    {
        $filter = new VehicleFilter();
        $filter->seats = 10;

        foreach ($this->repository->search($filter, 1, 100) as $vehicle) {
            self::assertGreaterThanOrEqual(10, $vehicle->getSeats());
        }
    }

    public function testFindActiveBrandsIsSortedAndUnique(): void
    {
        $brands = $this->repository->findActiveBrands();

        self::assertContains('Toyota', $brands);
        self::assertSame(array_values(array_unique($brands)), $brands);
        $sorted = $brands;
        natcasesort($sorted);
        self::assertSame(array_values($sorted), $brands);
    }

    public function testFindRelatedExcludesTheVehicle(): void
    {
        $porsche = $this->repository->findOneBy(['slug' => 'porsche-911']);
        $related = $this->repository->findRelated($porsche);

        self::assertCount(3, $related);
        foreach ($related as $vehicle) {
            self::assertSame('Luxury', $vehicle->getCategory());
            self::assertNotSame($porsche, $vehicle);
        }
    }

    public function testCountActiveByCategoryUsesV15Order(): void
    {
        $counts = $this->repository->countActiveByCategory();

        self::assertSame(Vehicle::CATEGORIES, array_keys($counts));
        self::assertSame(75, array_sum($counts));
    }
}
