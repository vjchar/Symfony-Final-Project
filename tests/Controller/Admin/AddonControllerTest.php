<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Addon;
use App\Repository\AddonRepository;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddonControllerTest extends WebTestCase
{
    use LogsInUsers;

    private const KEY = 'test-roof-rack';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->loginAsAdmin($this->client);
        $this->removeTestAddon();
    }

    protected function tearDown(): void
    {
        $this->removeTestAddon();
        parent::tearDown();
    }

    public function testListShowsSeededAddons(): void
    {
        $this->client->request('GET', '/admin/addons');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-table', 'Child safety seat');
        self::assertSelectorTextContains('.admin-table', 'One-time');
    }

    public function testCreateNormalisesKeyAndIconThenEdit(): void
    {
        $this->client->request('GET', '/admin/addons');
        $this->client->submitForm('Save Add-on', [
            'addon[addonKey]' => 'Test Roof_Rack',
            'addon[name]' => 'Roof rack',
            'addon[price]' => 300,
            'addon[icon]' => '',
        ]);

        self::assertResponseRedirects('/admin/addons');
        $addon = $this->addons()->findOneBy(['addonKey' => self::KEY]);
        self::assertNotNull($addon);
        self::assertSame('bi-plus-circle', $addon->getIcon());
        self::assertSame('rental', $addon->getBilling());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Rental add-on saved.');

        $this->client->request('GET', '/admin/addons?edit='.$addon->getId());
        self::assertSelectorTextContains('.admin-editor h2', 'Roof rack');
        $this->client->submitForm('Save Add-on', ['addon[price]' => 450]);
        self::assertSame(450, $this->addons()->findOneBy(['addonKey' => self::KEY])->getPrice());
    }

    public function testDuplicateKeyUsesV15Message(): void
    {
        $this->client->request('GET', '/admin/addons');
        $this->client->submitForm('Save Add-on', [
            'addon[addonKey]' => 'child-seat',
            'addon[name]' => 'Another seat',
            'addon[price]' => 100,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'That add-on key is already in use.');
    }

    private function addons(): AddonRepository
    {
        return static::getContainer()->get(AddonRepository::class);
    }

    private function removeTestAddon(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(Addon::class)->findBy(['addonKey' => self::KEY]) as $addon) {
            $em->remove($addon);
        }
        $em->flush();
    }
}
