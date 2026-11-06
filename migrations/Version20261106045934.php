<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106045934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create maintenance_records table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE maintenance_records (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(160) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(30) DEFAULT \'scheduled\' NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, cost INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, vehicle_id INT NOT NULL, created_by INT NOT NULL, INDEX idx_maintenance_vehicle_dates (vehicle_id, starts_at, ends_at, status), INDEX IDX_E56B1DD4545317D1 (vehicle_id), INDEX IDX_E56B1DD4DE12AB56 (created_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE maintenance_records ADD CONSTRAINT FK_E56B1DD4545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)');
        $this->addSql('ALTER TABLE maintenance_records ADD CONSTRAINT FK_E56B1DD4DE12AB56 FOREIGN KEY (created_by) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE maintenance_records DROP FOREIGN KEY FK_E56B1DD4545317D1');
        $this->addSql('ALTER TABLE maintenance_records DROP FOREIGN KEY FK_E56B1DD4DE12AB56');
        $this->addSql('DROP TABLE maintenance_records');
    }
}
