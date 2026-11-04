<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VehicleControllerTest extends WebTestCase
{
    public function testFleetListsActiveVehicles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vehicles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fleet-toolbar', '75 prepared vehicles');
        self::assertCount(75, $crawler->filter('.fleet-page .vehicle-card'));
        self::assertSelectorTextContains('.mockup-link.active', 'Vehicles');
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
