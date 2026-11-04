<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104061218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create vehicles and addons tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE addons (id INT AUTO_INCREMENT NOT NULL, addon_key VARCHAR(80) NOT NULL, name VARCHAR(140) NOT NULL, price INT NOT NULL, billing VARCHAR(20) DEFAULT \'rental\' NOT NULL, icon VARCHAR(80) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_DB652637BF565E12 (addon_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vehicles (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(140) NOT NULL, name VARCHAR(180) NOT NULL, brand VARCHAR(100) NOT NULL, category VARCHAR(40) NOT NULL, image VARCHAR(255) NOT NULL, price INT NOT NULL, deposit INT DEFAULT 5000 NOT NULL, seats INT NOT NULL, doors INT NOT NULL, luggage VARCHAR(80) NOT NULL, transmission VARCHAR(40) NOT NULL, fuel VARCHAR(40) NOT NULL, daily_km INT DEFAULT 200 NOT NULL, overview_title VARCHAR(255) NOT NULL, overview LONGTEXT NOT NULL, description LONGTEXT NOT NULL, inclusions JSON NOT NULL, features JSON NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, availability_status VARCHAR(30) DEFAULT \'available\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1FCE69FA989D9B62 (slug), INDEX idx_vehicles_category (category, is_active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE addons');
        $this->addSql('DROP TABLE vehicles');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
