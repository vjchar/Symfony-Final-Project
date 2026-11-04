<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VehicleControllerTest extends WebTestCase
{
    private const SLUG = 'test-toyota-vios';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $uploadedFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->removeTestVehicle();
    }

    protected function tearDown(): void
    {
        $this->removeTestVehicle();
        foreach ($this->uploadedFiles as $file) {
            @unlink(static::getContainer()->getParameter('kernel.project_dir').'/public/images/cars/'.$file);
        }
        parent::tearDown();
    }

    public function testListIsPaginatedTwelvePerPage(): void
    {
        $crawler = $this->client->request('GET', '/admin/vehicles');

        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('.admin-table tbody tr'));
        self::assertSelectorTextContains('.admin-pagination', 'Page 1 of 7');
        self::assertSelectorTextContains('.admin-editor h2', 'Vehicle details');

        $crawler = $this->client->request('GET', '/admin/vehicles?page=7');
        self::assertCount(3, $crawler->filter('.admin-table tbody tr'));
    }

    public function testCreateVehicleWithExistingImageThenEdit(): void
    {
        $this->client->request('GET', '/admin/vehicles');
        $this->client->submitForm('Create Vehicle', $this->formData());

        $vehicle = $this->vehicles()->findOneBy(['slug' => self::SLUG]);
        self::assertNotNull($vehicle);
        self::assertResponseRedirects('/admin/vehicles?edit='.$vehicle->getId());
        self::assertSame(['Standard rental protection', '24/7 roadside assistance'], $vehicle->getInclusions());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Vehicle created.');
        self::assertSelectorTextContains('.admin-editor h2', 'Toyota Vios');

        $this->client->submitForm('Save Vehicle', ['vehicle[price]' => 2100]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Vehicle updated.');
        self::assertSame(2100, $this->vehicles()->findOneBy(['slug' => self::SLUG])->getPrice());
    }

    public function testInactiveVehicleBecomesUnavailable(): void
    {
        $this->client->request('GET', '/admin/vehicles');
        $form = $this->client->getCrawler()->selectButton('Create Vehicle')->form($this->formData());
        $form['vehicle[isActive]']->untick();
        $this->client->submit($form);

        self::assertSame('unavailable', $this->vehicles()->findOneBy(['slug' => self::SLUG])->getAvailabilityStatus());
    }

    public function testUploadedImageIsStoredWithSlugPrefix(): void
    {
        $source = static::getContainer()->getParameter('kernel.project_dir').'/public/images/cars/toyota-fortuner.png';
        $tmp = tempnam(sys_get_temp_dir(), 'vj');
        copy($source, $tmp);

        $this->client->request('GET', '/admin/vehicles');
        $form = $this->client->getCrawler()->selectButton('Create Vehicle')->form(['vehicle[image]' => ''] + $this->formData());
        $form['vehicle[vehicleImage]']->upload($tmp);
        $this->client->submit($form);

        $image = $this->vehicles()->findOneBy(['slug' => self::SLUG])->getImage();
        $this->uploadedFiles[] = $image;
        self::assertMatchesRegularExpression('/^test-toyota-vios-[0-9a-f]{16}\.png$/', $image);
        self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/public/images/cars/'.$image);
    }

    public function testV15ValidationMessages(): void
    {
        $this->client->request('GET', '/admin/vehicles');
        $this->client->submitForm('Create Vehicle', [
            'vehicle[slug]' => 'porsche-911',
            'vehicle[price]' => 100,
            'vehicle[image]' => 'missing.png',
            'vehicle[features]' => '',
        ] + $this->formData());

        self::assertResponseStatusCodeSame(422);
        foreach ([
            'That vehicle slug is already used.',
            'Enter valid numeric vehicle values.',
            'Choose an uploaded image or enter an existing filename from assets/images/cars.',
            'Add at least one inclusion and one feature.',
        ] as $message) {
            self::assertSelectorTextContains('.admin-editor', $message);
        }
    }

    /** @return array<string, string|int> */
    private function formData(): array
    {
        return [
            'vehicle[name]' => 'Toyota Vios',
            'vehicle[slug]' => self::SLUG,
            'vehicle[brand]' => 'Toyota',
            'vehicle[category]' => 'SUV',
            'vehicle[price]' => 1800,
            'vehicle[deposit]' => 5000,
            'vehicle[dailyKm]' => 200,
            'vehicle[seats]' => 5,
            'vehicle[availabilityStatus]' => 'available',
            'vehicle[doors]' => 4,
            'vehicle[transmission]' => 'Automatic',
            'vehicle[fuel]' => 'Gasoline',
            'vehicle[luggage]' => '2 bags',
            'vehicle[image]' => 'toyota-fortuner.png',
            'vehicle[overviewTitle]' => 'An easy city car',
            'vehicle[overview]' => 'A compact sedan that is easy to park and light on fuel.',
            'vehicle[description]' => 'Choose the Toyota Vios for simple, economical city driving.',
            'vehicle[inclusions]' => "Standard rental protection\n24/7 roadside assistance",
            'vehicle[features]' => "Bluetooth audio\n\nRear camera\n",
        ];
    }

    // Fetched per call: the client reboots the kernel between requests, so a cached repository would read stale entities.
    private function vehicles(): VehicleRepository
    {
        return static::getContainer()->get(VehicleRepository::class);
    }

    private function removeTestVehicle(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(Vehicle::class)->findBy(['slug' => self::SLUG]) as $vehicle) {
            $em->remove($vehicle);
        }
        $em->flush();
    }
}
