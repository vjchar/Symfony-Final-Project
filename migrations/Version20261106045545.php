<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106045545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create promos table with the v15 VJ10 code';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE promos (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, description VARCHAR(255) NOT NULL, discount_type VARCHAR(20) NOT NULL, discount_value INT NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, max_uses INT DEFAULT NULL, used_count INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_31D1F70577153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // the v15 dump ships one promotion
        $this->addSql("INSERT INTO promos (id, code, description, discount_type, discount_value, starts_at, ends_at, max_uses, used_count, is_active, created_at, updated_at) VALUES (1, 'VJ10', 'Ten percent off the rental subtotal', 'percent', 10, NULL, NULL, 1000, 0, 1, '2026-09-01 11:31:54', '2026-09-01 11:31:54')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE promos');
    }
}
