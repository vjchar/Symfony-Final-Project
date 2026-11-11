<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110235528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking lifecycle: rejected and no-show timestamps, and overdue / missed-pickup notification markers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookings ADD rejected_at DATETIME DEFAULT NULL, ADD no_show_at DATETIME DEFAULT NULL, ADD overdue_notified_at DATETIME DEFAULT NULL, ADD missed_pickup_notified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookings DROP rejected_at, DROP no_show_at, DROP overdue_notified_at, DROP missed_pickup_notified_at');
    }
}
