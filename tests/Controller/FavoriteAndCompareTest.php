<?php

namespace App\Tests\Controller;

use App\Entity\Favorite;
use App\Tests\LogsInUsers;
use App\Tests\UploadsFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FavoriteAndCompareTest extends WebTestCase
{
    use LogsInUsers;
    use UploadsFiles;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearCustomerRecords();
    }

    protected function tearDown(): void
    {
        $this->clearCustomerRecords();
        parent::tearDown();
    }

    public function testHeartSavesAndRemovesAFavorite(): void
    {
        $this->client->loginUser($this->createUser('juan@test.vj'));

        $this->client->request('GET', '/account/favorites');
        self::assertSelectorTextContains('.empty-state h3', 'No favorites saved yet');

        $crawler = $this->client->request('GET', '/vehicles/toyota-fortuner');
        self::assertSelectorExists('.favorite-button--large[aria-label="Save Toyota Fortuner to favorites"]');
        $this->client->submit($crawler->filter('form.favorite-form--detail')->form());
        self::assertResponseRedirects('/vehicles/toyota-fortuner', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Vehicle saved to your favorites.');
        self::assertSelectorExists('.favorite-button--large.is-active');

        $crawler = $this->client->request('GET', '/account/favorites');
        self::assertSelectorTextContains('.fleet-toolbar h2', '1 saved car');
        self::assertSelectorTextContains('.vehicle-card h3', 'Toyota Fortuner');
        self::assertSelectorExists('.vehicle-card .favorite-button.is-active[aria-label="Remove Toyota Fortuner from favorites"]');

        $this->client->submit($crawler->filter('.vehicle-card form.favorite-form')->form());
        self::assertResponseRedirects('/account/favorites', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Vehicle removed from your favorites.');
        self::assertSame(0, static::getContainer()->get('doctrine')->getRepository(Favorite::class)->count([]));
    }

    public function testFavoritesNeedACustomerAccount(): void
    {
        $this->client->request('GET', '/vehicles/toyota-fortuner');
        self::assertSelectorExists('a.favorite-button--large[href="/login?return_to=/vehicles/toyota-fortuner"]');

        $this->client->request('POST', '/favorites/toyota-fortuner');
        self::assertResponseRedirects();
        self::assertSame(0, static::getContainer()->get('doctrine')->getRepository(Favorite::class)->count([]));

        $this->loginAsAdmin($this->client);
        $this->client->request('GET', '/vehicles/toyota-fortuner');
        self::assertSelectorNotExists('.favorite-button');
        $this->client->request('GET', '/account/favorites');
        self::assertResponseStatusCodeSame(403);
    }

    public function testATamperedTokenChangesNothing(): void
    {
        $this->client->loginUser($this->createUser('juan@test.vj'));
        $this->client->request('POST', '/favorites/toyota-fortuner', ['_token' => 'forged', 'return_to' => 'https://evil.example/']);

        self::assertResponseRedirects('/account/favorites', 303, 'an off-site return_to is ignored');
        self::assertSame(0, static::getContainer()->get('doctrine')->getRepository(Favorite::class)->count([]));
    }

    public function testCompareShowsTheChosenVehiclesSideBySide(): void
    {
        $this->client->request('GET', '/compare?compare[]=toyota-fortuner&compare[]=suzuki-jimny&compare[]=unknown-car');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.compare-table thead th strong');
        self::assertSelectorTextContains('.compare-table thead', 'Toyota Fortuner');
        self::assertSelectorTextContains('.compare-table thead', 'Suzuki Jimny');
        self::assertSelectorExists('select[name="compare[]"] option[value="toyota-fortuner"][selected]');
        self::assertSelectorTextContains('.compare-table td.is-best', 'Lowest rate');

        $this->client->request('GET', '/compare?vehicles=toyota-fortuner');
        self::assertSelectorCount(1, '.compare-table thead th strong');

        // nothing chosen: the first three of the fleet
        $this->client->request('GET', '/compare');
        self::assertSelectorCount(3, '.compare-table thead th strong');
    }

    public function testFleetLinksPointToTheRealPages(): void
    {
        $this->client->request('GET', '/vehicles');
        self::assertSelectorExists('a.compare-toggle[href^="/compare?compare"]');
        self::assertSelectorExists('footer a[href="/compare"]');
        self::assertSelectorNotExists('a[href^="/coming-soon/compare"], a[href^="/coming-soon/favorites"]');
    }
}
