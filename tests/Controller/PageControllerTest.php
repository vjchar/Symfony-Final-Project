<?php

namespace App\Tests\Controller;

use App\Tests\LogsInUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageControllerTest extends WebTestCase
{
    use LogsInUsers;

    /** @return iterable<array{string, string}> */
    public static function publicPages(): iterable
    {
        yield 'home' => ['/', 'Your Journey'];
        yield 'about' => ['/about', 'About'];
        yield 'services' => ['/services', 'Services'];
        yield 'contact' => ['/contact', 'Contact'];
        yield 'faq' => ['/faq', 'FAQ'];
        yield 'terms' => ['/terms', 'Terms'];
        yield 'privacy' => ['/privacy', 'Privacy'];
    }

    #[DataProvider('publicPages')]
    public function testPublicPageUsesV15Layout(string $url, string $titlePart): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains($titlePart);
        self::assertSelectorExists('header.site-header .mockup-links');
        self::assertSelectorExists('footer.site-footer');
        self::assertSelectorTextContains('.cta-band', 'Ready to hit the road?');
    }

    public function testActiveNavigationLinkIsHighlighted(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertSelectorTextContains('.mockup-link.active', 'About Us');
    }

    public function testFaqRendersAllQuestionsWithFirstOpen(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/faq');

        self::assertCount(10, $crawler->filter('details.faq-item'));
        self::assertCount(1, $crawler->filter('details.faq-item[open]'));
        self::assertSame('requirements', $crawler->filter('details.faq-item[open]')->attr('id'));
    }

    public function testTermsShowRefundCutoff(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');

        self::assertSelectorTextContains('body', 'more than 24 hours before the scheduled pickup');
    }

    public function testFeaturesFromLaterStepsLinkToComingSoon(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $bookLink = $crawler->filter('.mockup-book-button')->attr('href');
        self::assertSame('/coming-soon/booking', $bookLink);

        $client->request('GET', $bookLink);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Booking is on the way.');
    }

    public function testAdminDashboardUsesOperationsLayout(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.is-admin-context');
        self::assertSelectorTextContains('.admin-nav .active', 'Dashboard');
        self::assertSelectorTextContains('.admin-dashboard-hero h1', 'Vina');
        self::assertSelectorNotExists('.cta-band');
    }

    public function testAdminPlaceholderKeepsAdminLayout(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $client->request('GET', '/coming-soon/admin-bookings');

        self::assertSelectorExists('body.is-admin-context');
        self::assertSelectorTextContains('.admin-nav .active', 'Bookings');
        self::assertSelectorTextContains('h1', 'Bookings is on the way.');
    }
}
