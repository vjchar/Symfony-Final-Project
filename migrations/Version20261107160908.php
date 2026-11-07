<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107160908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking lifecycle timestamps and admin notes for the rental desk';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bookings ADD ready_at DATETIME DEFAULT NULL, ADD started_at DATETIME DEFAULT NULL, ADD returned_at DATETIME DEFAULT NULL, ADD completed_at DATETIME DEFAULT NULL, ADD admin_notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bookings DROP ready_at, DROP started_at, DROP returned_at, DROP completed_at, DROP admin_notes');
    }
}
