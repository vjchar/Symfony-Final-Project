<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111085728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit log: record the role (admin, staff, customer) the actor had when they acted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs ADD actor_role VARCHAR(20) DEFAULT NULL');
        // earlier entries take the actor's current role, the closest record there is
        $this->addSql('UPDATE audit_logs a INNER JOIN users u ON u.id = a.user_id SET a.actor_role = u.role');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs DROP actor_role');
    }
}
