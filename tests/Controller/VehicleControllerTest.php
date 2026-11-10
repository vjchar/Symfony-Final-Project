<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VehicleControllerTest extends WebTestCase
{
    public function testFleetListsActiveVehiclesTwelvePerPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vehicles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fleet-toolbar', '75 prepared vehicles');
        self::assertSelectorTextContains('.fleet-results-header', '75 vehicles found');
        self::assertCount(12, $crawler->filter('.fleet-page .vehicle-card'));
        self::assertSelectorTextContains('.fleet-pagination', 'Page 1 of 7');
        self::assertSelectorTextContains('.mockup-link.active', 'Vehicles');

        $crawler = $client->request('GET', '/vehicles?page=7');
        self::assertCount(3, $crawler->filter('.fleet-page .vehicle-card'));
    }

    public function testFiltersUseV15QueryParameters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vehicles?category=EV&max_price=5000&sort=price-asc');

        $prices = $crawler->filter('.fleet-page .vehicle-price-row strong')->each(
            static fn ($node): int => (int) preg_replace('/\D/', '', strtok($node->text(), '/')),
        );
        self::assertNotEmpty($prices);
        self::assertSame($prices, array_values(array_filter($prices, static fn (int $p): bool => $p <= 5000)));
        $sorted = $prices;
        sort($sorted);
        self::assertSame($sorted, $prices);
        self::assertSelectorTextContains('.fleet-filter-pills a.active', 'EV');
        self::assertSame('5000', $crawler->filter('select[name="max_price"] option[selected]')->attr('value'));
    }

    public function testSearchMatchesBrandAndModel(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles?search=toyota hilux');

        self::assertSelectorTextContains('.fleet-results-header', '2 vehicles found');
    }

    public function testInvalidFilterValuesResetToDefaults(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles?category=Bogus&seats=99');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fleet-results-header', '75 vehicles found');
    }

    public function testCategoryPillsKeepOtherFilters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vehicles?transmission=Manual');

        $pickupLink = $crawler->filter('.fleet-filter-pills a')->eq(3)->attr('href');
        self::assertStringContainsString('transmission=Manual', $pickupLink);
        self::assertStringContainsString('category=Pickup', $pickupLink);
    }

    public function testNoResultsShowsEmptyState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles?search=zzzz');

        self::assertSelectorTextContains('.empty-state h3', 'No vehicles match those filters.');
        self::assertSelectorNotExists('.fleet-pagination');
    }

    public function testVehicleCardUsesPlainImagePathForV15ImageStyles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vehicles');

        self::assertSame('/images/cars/porsche-911.png', $crawler->filter('.vehicle-card img')->first()->attr('src'));
    }

    public function testDetailShowsVehicleRelatedAndRecentlyViewed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/mclaren-720s');
        $crawler = $client->request('GET', '/vehicles/porsche-911');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Porsche 911 Carrera S');
        self::assertSelectorTextContains('.detail-price', '₱35,000');
        self::assertSelectorTextContains('.included-card', '150 km daily mileage allowance');
        self::assertCount(3, $crawler->filter('.related-vehicles-section .vehicle-card'));
        self::assertSelectorTextContains('.recently-viewed-grid', 'McLaren 720S');
    }

    public function testUnknownVehicleIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vehicles/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHomeShowsFeaturedVehiclesAndCategoryShelves(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(4, $crawler->filter('.featured-vehicle-row .vehicle-card'));
        self::assertSame('Porsche 911 Carrera S', trim($crawler->filter('.featured-vehicle-row .vehicle-card h3')->first()->text()));
        self::assertCount(5, $crawler->filter('.fleet-shelf'));
        self::assertCount(75, $crawler->filter('.fleet-shelf .vehicle-card--compact'));
    }

    public function testServicesListActiveAddons(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/services');

        self::assertCount(5, $crawler->filterXPath('//article[contains(@class, "service-card")][contains(., "charged once per booking")]'));
        self::assertAnySelectorTextContains('.service-card p', '₱250 per seat, charged once per booking.');
    }
}
