<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106051633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bookings, booking_addons, booking_drafts and booking_draft_addons tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking_addons (id INT AUTO_INCREMENT NOT NULL, addon_name VARCHAR(140) NOT NULL, unit_price INT NOT NULL, billing VARCHAR(20) NOT NULL, quantity INT DEFAULT 1 NOT NULL, line_total INT NOT NULL, addon_id INT NOT NULL, booking_id INT NOT NULL, INDEX IDX_60FF1DC9CC642678 (addon_id), INDEX IDX_60FF1DC93301C60 (booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE booking_draft_addons (id INT AUTO_INCREMENT NOT NULL, addon_name VARCHAR(140) NOT NULL, unit_price INT NOT NULL, billing VARCHAR(20) NOT NULL, quantity INT DEFAULT 1 NOT NULL, line_total INT NOT NULL, addon_id INT NOT NULL, draft_id INT NOT NULL, UNIQUE INDEX draft_addon_unique (draft_id, addon_id), INDEX IDX_922C8D25CC642678 (addon_id), INDEX IDX_922C8D25E2F3C5D1 (draft_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE booking_drafts (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(40) NOT NULL, pickup_at DATETIME NOT NULL, return_at DATETIME NOT NULL, pickup_method VARCHAR(40) NOT NULL, pickup_location VARCHAR(140) NOT NULL, delivery_address VARCHAR(255) DEFAULT \'\', promo_code VARCHAR(40) DEFAULT \'\', estimated_subtotal INT DEFAULT 0 NOT NULL, estimated_addons_total INT DEFAULT 0 NOT NULL, estimated_delivery_fee INT DEFAULT 0 NOT NULL, estimated_discount INT DEFAULT 0 NOT NULL, estimated_total INT DEFAULT 0 NOT NULL, estimated_deposit INT DEFAULT 0 NOT NULL, special_requests LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, expires_at DATETIME NOT NULL, last_saved_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, vehicle_id INT NOT NULL, converted_booking_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_57B0DF3EAEA34913 (reference), INDEX idx_booking_drafts_user_status (user_id, status, updated_at), INDEX idx_booking_drafts_expires (status, expires_at), INDEX IDX_57B0DF3EA76ED395 (user_id), INDEX IDX_57B0DF3E545317D1 (vehicle_id), INDEX IDX_57B0DF3EB778ED36 (converted_booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bookings (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(40) NOT NULL, pickup_at DATETIME NOT NULL, return_at DATETIME NOT NULL, pickup_method VARCHAR(40) NOT NULL, pickup_location VARCHAR(140) NOT NULL, delivery_address VARCHAR(255) DEFAULT \'\', status VARCHAR(30) DEFAULT \'pending\' NOT NULL, promo_code VARCHAR(40) DEFAULT \'\', subtotal INT NOT NULL, addons_total INT DEFAULT 0 NOT NULL, delivery_fee INT DEFAULT 0 NOT NULL, discount INT DEFAULT 0 NOT NULL, total INT NOT NULL, deposit INT NOT NULL, special_requests LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, vehicle_id INT NOT NULL, UNIQUE INDEX UNIQ_7A853C35AEA34913 (reference), INDEX idx_bookings_vehicle_dates (vehicle_id, pickup_at, return_at, status), INDEX idx_bookings_user (user_id, created_at), INDEX IDX_7A853C35A76ED395 (user_id), INDEX IDX_7A853C35545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking_addons ADD CONSTRAINT FK_60FF1DC9CC642678 FOREIGN KEY (addon_id) REFERENCES addons (id)');
        $this->addSql('ALTER TABLE booking_addons ADD CONSTRAINT FK_60FF1DC93301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_draft_addons ADD CONSTRAINT FK_922C8D25CC642678 FOREIGN KEY (addon_id) REFERENCES addons (id)');
        $this->addSql('ALTER TABLE booking_draft_addons ADD CONSTRAINT FK_922C8D25E2F3C5D1 FOREIGN KEY (draft_id) REFERENCES booking_drafts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_drafts ADD CONSTRAINT FK_57B0DF3EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_drafts ADD CONSTRAINT FK_57B0DF3E545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)');
        $this->addSql('ALTER TABLE booking_drafts ADD CONSTRAINT FK_57B0DF3EB778ED36 FOREIGN KEY (converted_booking_id) REFERENCES bookings (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT FK_7A853C35A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT FK_7A853C35545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking_addons DROP FOREIGN KEY FK_60FF1DC9CC642678');
        $this->addSql('ALTER TABLE booking_addons DROP FOREIGN KEY FK_60FF1DC93301C60');
        $this->addSql('ALTER TABLE booking_draft_addons DROP FOREIGN KEY FK_922C8D25CC642678');
        $this->addSql('ALTER TABLE booking_draft_addons DROP FOREIGN KEY FK_922C8D25E2F3C5D1');
        $this->addSql('ALTER TABLE booking_drafts DROP FOREIGN KEY FK_57B0DF3EA76ED395');
        $this->addSql('ALTER TABLE booking_drafts DROP FOREIGN KEY FK_57B0DF3E545317D1');
        $this->addSql('ALTER TABLE booking_drafts DROP FOREIGN KEY FK_57B0DF3EB778ED36');
        $this->addSql('ALTER TABLE bookings DROP FOREIGN KEY FK_7A853C35A76ED395');
        $this->addSql('ALTER TABLE bookings DROP FOREIGN KEY FK_7A853C35545317D1');
        $this->addSql('DROP TABLE booking_addons');
        $this->addSql('DROP TABLE booking_draft_addons');
        $this->addSql('DROP TABLE booking_drafts');
        $this->addSql('DROP TABLE bookings');
    }
}
