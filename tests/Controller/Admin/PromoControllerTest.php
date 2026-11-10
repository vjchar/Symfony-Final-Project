<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Promo;
use App\Tests\LogsInUsers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PromoControllerTest extends WebTestCase
{
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // keep the seeded v15 VJ10 code, drop anything a test made
        $this->em()->createQuery('DELETE FROM '.Promo::class.' p WHERE p.code <> :seed')->execute(['seed' => 'VJ10']);
        $this->loginAsAdmin($this->client);
    }

    public function testListsTheSeededV15Code(): void
    {
        $this->client->request('GET', '/admin/promos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Promotion codes');
        self::assertAnySelectorTextContains('.admin-table td', 'VJ10');
        self::assertAnySelectorTextContains('.admin-table td', '10%');
        self::assertAnySelectorTextContains('.admin-table td', '0 / 1000');
        self::assertSelectorTextContains('.admin-editor h2', 'Create a code');
        self::assertCheckboxChecked('promo[isActive]');
    }

    public function testNavLinksToPromotions(): void
    {
        $this->client->request('GET', '/admin');

        self::assertSelectorExists('a[href="/admin/promos"]');
    }

    public function testCreatesAFixedAmountCode(): void
    {
        $this->client->request('GET', '/admin/promos');
        $this->client->submitForm('Save Promotion', [
            'promo[code]' => ' flat 500! ',
            'promo[description]' => 'Five hundred off weekend trips',
            'promo[discountType]' => 'fixed',
            'promo[discountValue]' => '500',
            'promo[startsAt]' => '2026-12-01',
            'promo[endsAt]' => '2026-12-31',
            'promo[maxUses]' => '',
        ]);

        self::assertResponseRedirects('/admin/promos', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Promotion saved.');
        self::assertAnySelectorTextContains('.admin-table td', '₱500');

        $promo = $this->findPromo('FLAT500');
        self::assertNotNull($promo, 'the code is upper-cased and stripped like v15');
        self::assertSame('2026-12-01 00:00:00', $promo->getStartsAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 23:59:59', $promo->getEndsAt()->format('Y-m-d H:i:s'));
        self::assertNull($promo->getMaxUses());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'promotion_saved', 'entityId' => $promo->getId()]));
    }

    public function testEditsAndDeactivatesACode(): void
    {
        $promo = $this->createPromo('SUMMER25');

        $this->client->request('GET', '/admin/promos?edit='.$promo->getId());
        self::assertSelectorTextContains('.admin-editor h2', 'SUMMER25');
        self::assertInputValueSame('promo[code]', 'SUMMER25');

        $this->client->submitForm('Save Promotion', ['promo[discountValue]' => '30', 'promo[isActive]' => false]);
        self::assertResponseRedirects('/admin/promos', 303);

        $promo = $this->findPromo('SUMMER25');
        self::assertSame(30, $promo->getDiscountValue());
        self::assertFalse($promo->isActive());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidPromos(): iterable
    {
        yield 'no code' => [['promo[code]' => '!!!'], 'Enter a promotion code using letters, numbers, underscores, or hyphens.'];
        yield 'short description' => [['promo[description]' => 'Hi'], 'Enter a description between 3 and 255 characters.'];
        yield 'percent over 100' => [['promo[discountValue]' => '150'], 'Enter a valid discount value.'];
        yield 'zero value' => [['promo[discountType]' => 'fixed', 'promo[discountValue]' => '0'], 'Enter a valid discount value.'];
        yield 'zero max uses' => [['promo[maxUses]' => '0'], 'Maximum uses must be a positive whole number or blank.'];
        yield 'ends before start' => [['promo[startsAt]' => '2026-12-10', 'promo[endsAt]' => '2026-12-01'], 'End date must be after the start date.'];
        yield 'duplicate code' => [['promo[code]' => 'vj10'], 'The promotion could not be saved. The code may already be in use.'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('invalidPromos')]
    public function testRejectsInvalidCodesWithV15Messages(array $fields, string $message): void
    {
        $this->client->request('GET', '/admin/promos');
        $this->client->submitForm('Save Promotion', $fields + [
            'promo[code]' => 'NEWCODE',
            'promo[description]' => 'A valid description',
            'promo[discountValue]' => '10',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', $message);
        self::assertNull($this->findPromo('NEWCODE'));
    }

    public function testFailedEditKeepsTheSavedCodeInTheTable(): void
    {
        $promo = $this->createPromo('SUMMER25');

        $this->client->request('GET', '/admin/promos?edit='.$promo->getId());
        $this->client->submitForm('Save Promotion', ['promo[code]' => 'WINTER', 'promo[description]' => 'No']);

        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('promo[code]', 'WINTER');
        self::assertSelectorTextContains('.admin-editor h2', 'SUMMER25');
        self::assertAnySelectorTextContains('.admin-table strong', 'SUMMER25');
    }

    public function testDeletesOnlyUnusedCodes(): void
    {
        $unused = $this->createPromo('SUMMER25');
        $used = $this->createPromo('USED5', usedCount: 3);

        $crawler = $this->client->request('GET', '/admin/promos');
        $row = $crawler->filter('.admin-table tr')->reduce(fn ($tr) => str_contains($tr->text(), 'USED5'));
        self::assertStringContainsString('Deactivate to retain usage history', $row->text());
        self::assertCount(0, $row->filter('form[data-confirm]'));

        $deleteForm = $crawler->filter(sprintf('form[action="/admin/promos/%d/delete"]', $unused->getId()));
        self::assertSame('Delete this unused promotion? This action cannot be undone.', $deleteForm->attr('data-confirm'));
        $this->client->submit($deleteForm->form());

        self::assertResponseRedirects('/admin/promos', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Promotion SUMMER25 was deleted.');
        self::assertNull($this->findPromo('SUMMER25'));

        // a crafted request for a used code is still refused
        $this->client->request('POST', sprintf('/admin/promos/%d/delete', $used->getId()), ['_token' => 'wrong']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Your session expired.');
        self::assertNotNull($this->findPromo('USED5'));
    }

    public function testCodeUsedAfterThePageLoadedIsNotDeleted(): void
    {
        $promo = $this->createPromo('SUMMER25');
        $crawler = $this->client->request('GET', '/admin/promos');
        $form = $crawler->filter(sprintf('form[action="/admin/promos/%d/delete"]', $promo->getId()))->form();

        // a booking redeems the code while the page is open
        $this->em()->createQuery('UPDATE '.Promo::class.' p SET p.usedCount = 1 WHERE p.id = :id')->execute(['id' => $promo->getId()]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'A promotion that has already been used cannot be deleted. Deactivate it instead.');
        self::assertNotNull($this->findPromo('SUMMER25'));
    }

    private function createPromo(string $code, int $usedCount = 0): Promo
    {
        $promo = (new Promo())->setCode($code)->setDescription('Test promotion')->setDiscountValue(25);
        (new \ReflectionProperty(Promo::class, 'usedCount'))->setValue($promo, $usedCount);
        $this->em()->persist($promo);
        $this->em()->flush();

        return $promo;
    }

    private function findPromo(string $code): ?Promo
    {
        $this->em()->clear();

        return $this->em()->getRepository(Promo::class)->findOneBy(['code' => $code]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
