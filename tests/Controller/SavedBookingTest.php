<?php

namespace App\Tests\Controller;

use App\Entity\BookingDraft;
use App\Entity\Promo;
use App\Tests\BuildsBookings;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SavedBookingTest extends WebTestCase
{
    use BuildsBookings;
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearBookings();
        $this->em()->createQuery('DELETE FROM '.Promo::class.' p WHERE p.code <> :seed')->execute(['seed' => 'VJ10']);
        $this->client->loginUser($this->createUser('juan@test.vj', name: 'Juan Dela Cruz'));
    }

    protected function tearDown(): void
    {
        $this->clearBookings();
        parent::tearDown();
    }

    public function testSaveForLaterKeepsADraftWithoutReserving(): void
    {
        $this->submitBooking($this->client, ['addons' => ['gps'], 'promo' => 'VJ10', 'terms' => null], 'save_draft');

        $draft = $this->drafts()[0];
        self::assertResponseRedirects(sprintf('/account/saved-bookings?draft=%d&saved=1', $draft->getId()), 303);
        self::assertMatchesRegularExpression('/^DRAFT-\d{6}-[0-9A-F]{6}$/', $draft->getReference());
        self::assertSame(18600 + 150 - 1860, $draft->getEstimatedTotal());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), $draft->getExpiresAt()->getTimestamp(), 60);
        self::assertCount(0, $this->bookings());
        self::assertSame(0, $this->em()->getRepository(Promo::class)->findOneBy(['code' => 'VJ10'])->getUsedCount(), 'drafts never use up a promotion');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Booking saved for later. The vehicle is not reserved until you proceed with the booking.');
        self::assertSelectorTextContains('.operations-page .alert-success strong', 'Booking saved.');
        self::assertSelectorTextContains('.draft-detail-card h2', 'Toyota Fortuner');
        self::assertSelectorTextContains('.draft-current-check', 'Vehicle Available');
        self::assertSelectorTextContains('.draft-current-check', 'No change');
        self::assertSelectorTextContains('.saved-draft-card .section-kicker', $draft->getReference());

        // the draft does not hold the dates
        $this->submitBooking($this->client, [], 'preview');
        self::assertSelectorTextContains('#bookingVehicleMessage.is-success', 'Available for the selected schedule.');
    }

    public function testContinuingADraftLoadsItAndBooksIt(): void
    {
        $this->submitBooking($this->client, ['addons' => ['child-seat'], 'addon_quantities' => ['child-seat' => '3']], 'save_draft');
        $draft = $this->drafts()[0];

        $url = '/booking?draft='.$draft->getId();
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('.alert-info', 'Saved booking '.$draft->getReference().' loaded.');
        self::assertSelectorTextContains('#bookingVehicleMessage.is-success', 'Saved booking loaded.');
        self::assertSelectorExists('input[name="addons[]"][value="child-seat"][checked]');
        self::assertSelectorTextContains('#childSeatQuantity option[selected]', '3 seats');
        self::assertSelectorTextContains('button[value="save_draft"]', 'Update Saved Booking');

        $this->submitBooking($this->client, ['addons' => ['child-seat'], 'addon_quantities' => ['child-seat' => '3']], url: $url);

        $booking = $this->bookings()[0];
        self::assertResponseRedirects('/booking/'.$booking->getReference().'/confirmation', 303);
        self::assertSame(3, $booking->getAddons()->first()->getQuantity());
        $draft = $this->drafts()[0];
        self::assertSame(BookingDraft::STATUS_CONVERTED, $draft->getStatus());
        self::assertSame($booking->getId(), $draft->getConvertedBooking()?->getId());

        // a converted draft can't be continued again
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/account/saved-bookings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'That saved booking is unavailable or has expired.');
    }

    public function testUpdatingADraftReplacesItsChoices(): void
    {
        $this->submitBooking($this->client, ['addons' => ['gps', 'wifi']], 'save_draft');
        $draft = $this->drafts()[0];

        $this->submitBooking($this->client, ['addons' => ['gps'], 'location' => 'Bais City Branch'], 'save_draft', '/booking?draft='.$draft->getId());

        self::assertResponseRedirects();
        $drafts = $this->drafts();
        self::assertCount(1, $drafts, 'the same draft is updated');
        self::assertSame(['gps'], $drafts[0]->getAddons()->map(static fn ($line) => $line->getAddon()->getAddonKey())->toArray());
        self::assertSame(18600 + 150, $drafts[0]->getEstimatedTotal());
    }

    public function testDiscardingADraft(): void
    {
        $this->submitBooking($this->client, [], 'save_draft');
        $draft = $this->drafts()[0];

        $crawler = $this->client->request('GET', '/account/saved-bookings');
        $form = $crawler->filter(sprintf('form[action="/account/saved-bookings/%d/discard"]', $draft->getId()))->form();
        self::assertSame('Discard this saved booking?', $crawler->filter('.saved-draft-card form')->attr('data-confirm'));
        $this->client->submit($form);

        self::assertResponseRedirects('/account/saved-bookings', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Saved booking discarded. No vehicle reservation was affected.');
        self::assertSelectorTextContains('.empty-state h2', 'No saved bookings yet');
        self::assertSame(BookingDraft::STATUS_DISCARDED, $this->drafts()[0]->getStatus());

        // the details view offers to reuse it instead
        $this->client->request('GET', '/account/saved-bookings?draft='.$draft->getId());
        self::assertSelectorTextContains('.draft-detail-card .status-badge', 'Discarded');
        self::assertSelectorExists(sprintf('a[href="/booking?source_draft=%d"]', $draft->getId()));

        $this->client->click($this->client->getCrawler()->selectLink('Use These Details Again')->link());
        self::assertSelectorTextContains('.alert-info', 'Previous draft details loaded as a new booking plan.');
        self::assertSelectorTextContains('button[value="save_draft"]', 'Save for Later');
    }

    public function testDraftsExpireAfterSevenDays(): void
    {
        $this->submitBooking($this->client, [], 'save_draft');
        $draft = $this->drafts()[0];
        $this->em()->createQuery('UPDATE '.BookingDraft::class.' d SET d.expiresAt = :past WHERE d.id = :id')
            ->execute(['past' => new \DateTimeImmutable('-1 minute'), 'id' => $draft->getId()]);

        $this->client->request('GET', '/account/saved-bookings');
        self::assertSelectorTextContains('.empty-state h2', 'No saved bookings yet');
        self::assertSame(BookingDraft::STATUS_EXPIRED, $this->drafts()[0]->getStatus());

        $this->client->request('GET', '/booking?draft='.$draft->getId());
        self::assertResponseRedirects('/account/saved-bookings');
    }

    public function testALapsedPromotionIsDroppedFromTheDraftPreview(): void
    {
        $promo = (new Promo())->setCode('WEEKEND')->setDescription('Weekend special')->setDiscountType('fixed')->setDiscountValue(1000);
        $this->em()->persist($promo);
        $this->em()->flush();
        $this->submitBooking($this->client, ['promo' => 'WEEKEND'], 'save_draft');
        $draft = $this->drafts()[0];
        $this->em()->createQuery('UPDATE '.Promo::class.' p SET p.isActive = false WHERE p.code = :code')->execute(['code' => 'WEEKEND']);

        $this->client->request('GET', '/account/saved-bookings?draft='.$draft->getId());
        self::assertSelectorTextContains('.alert-warning', 'The promotion saved with this booking is no longer available.');
        self::assertSelectorTextContains('.draft-current-check', '+₱1,000');

        $this->client->request('GET', '/booking?draft='.$draft->getId());
        self::assertAnySelectorTextContains('.alert-warning', 'Price updated.');
        self::assertInputValueSame('promo', '');
    }

    public function testDraftsBelongToTheirOwner(): void
    {
        $this->submitBooking($this->client, [], 'save_draft');
        $draft = $this->drafts()[0];

        $this->client->loginUser($this->createUser('maria@test.vj'));
        $this->client->request('GET', '/booking?draft='.$draft->getId());
        self::assertResponseRedirects('/account/saved-bookings');

        $this->client->request('GET', '/account/saved-bookings?draft='.$draft->getId());
        self::assertSelectorNotExists('.draft-detail-card');

        $this->client->request('POST', sprintf('/account/saved-bookings/%d/discard', $draft->getId()), ['_token' => 'wrong']);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        self::assertSame(BookingDraft::STATUS_ACTIVE, $this->drafts()[0]->getStatus());
    }

    public function testAccountDashboardShowsTheLatestDraft(): void
    {
        $this->submitBooking($this->client, [], 'save_draft');

        $this->client->request('GET', '/account');

        self::assertSelectorTextContains('.dashboard-saved-draft h2', 'Toyota Fortuner');
        self::assertSelectorTextContains('.account-stats', '1Saved bookings');
        self::assertSelectorNotExists('.dashboard-priority-action', 'a draft is not a booking that needs action');
    }

    public function testATamperedFormIsRefused(): void
    {
        $this->submitBooking($this->client, ['_token' => 'wrong'], 'save_draft');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Your session expired. Refresh the page and try again.');
        self::assertCount(0, $this->drafts());
    }
}
