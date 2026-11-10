<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Loads the VJ Car Rental v15 reference data (fleet and add-ons) taken from
 * database/vj_car_rental_v15.sql.
 */
final class Version20261104061231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed vehicles and addons from the VJ Car Rental v15 dump';
    }

    public function up(Schema $schema): void
    {
        $this->addSql((string) file_get_contents(__DIR__.'/data/vehicles.sql'));
        $this->addSql((string) file_get_contents(__DIR__.'/data/addons.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM addons');
        $this->addSql('DELETE FROM vehicles');
    }
}
