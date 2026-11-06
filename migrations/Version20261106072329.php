<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106072329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking modification, cancellation and rental adjustment requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking_cancellation_requests (id INT AUTO_INCREMENT NOT NULL, reason LONGTEXT NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, refundable_amount INT DEFAULT 0 NOT NULL, non_refundable_amount INT DEFAULT 0 NOT NULL, admin_note LONGTEXT DEFAULT NULL, requested_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, user_id INT NOT NULL, reviewed_by INT DEFAULT NULL, INDEX idx_booking_cancellation_booking_status (booking_id, status), INDEX idx_booking_cancellation_user (user_id, requested_at), INDEX IDX_F42DDE043301C60 (booking_id), INDEX IDX_F42DDE04A76ED395 (user_id), INDEX IDX_F42DDE0485D7FB47 (reviewed_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE booking_modification_requests (id INT AUTO_INCREMENT NOT NULL, request_type VARCHAR(30) DEFAULT \'pre_pickup\' NOT NULL, original_data JSON NOT NULL, requested_data JSON NOT NULL, price_difference INT DEFAULT 0 NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, customer_reason LONGTEXT DEFAULT NULL, admin_note LONGTEXT DEFAULT NULL, requested_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, activated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, user_id INT NOT NULL, requested_vehicle_id INT DEFAULT NULL, reviewed_by INT DEFAULT NULL, INDEX idx_booking_modification_booking_status (booking_id, status), INDEX idx_booking_modification_user (user_id, requested_at), INDEX IDX_2CC102273301C60 (booking_id), INDEX IDX_2CC10227A76ED395 (user_id), INDEX IDX_2CC102272A12158C (requested_vehicle_id), INDEX IDX_2CC1022785D7FB47 (reviewed_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rental_adjustment_requests (id INT AUTO_INCREMENT NOT NULL, request_type VARCHAR(30) NOT NULL, original_return_at DATETIME NOT NULL, requested_return_at DATETIME NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, price_difference INT DEFAULT 0 NOT NULL, customer_reason VARCHAR(1500) DEFAULT \'\', admin_note VARCHAR(1500) DEFAULT \'\', reviewed_at DATETIME DEFAULT NULL, activated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, user_id INT NOT NULL, reviewed_by INT DEFAULT NULL, INDEX idx_adjustment_booking_status (booking_id, status), INDEX idx_adjustment_user_status (user_id, status, created_at), INDEX idx_adjustment_type_status (request_type, status, requested_return_at), INDEX IDX_FDF434993301C60 (booking_id), INDEX IDX_FDF43499A76ED395 (user_id), INDEX IDX_FDF4349985D7FB47 (reviewed_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking_cancellation_requests ADD CONSTRAINT FK_F42DDE043301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_cancellation_requests ADD CONSTRAINT FK_F42DDE04A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_cancellation_requests ADD CONSTRAINT FK_F42DDE0485D7FB47 FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE booking_modification_requests ADD CONSTRAINT FK_2CC102273301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_modification_requests ADD CONSTRAINT FK_2CC10227A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_modification_requests ADD CONSTRAINT FK_2CC102272A12158C FOREIGN KEY (requested_vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE booking_modification_requests ADD CONSTRAINT FK_2CC1022785D7FB47 FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE rental_adjustment_requests ADD CONSTRAINT FK_FDF434993301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rental_adjustment_requests ADD CONSTRAINT FK_FDF43499A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rental_adjustment_requests ADD CONSTRAINT FK_FDF4349985D7FB47 FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bookings ADD original_return_at DATETIME DEFAULT NULL, ADD cancelled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking_cancellation_requests DROP FOREIGN KEY FK_F42DDE043301C60');
        $this->addSql('ALTER TABLE booking_cancellation_requests DROP FOREIGN KEY FK_F42DDE04A76ED395');
        $this->addSql('ALTER TABLE booking_cancellation_requests DROP FOREIGN KEY FK_F42DDE0485D7FB47');
        $this->addSql('ALTER TABLE booking_modification_requests DROP FOREIGN KEY FK_2CC102273301C60');
        $this->addSql('ALTER TABLE booking_modification_requests DROP FOREIGN KEY FK_2CC10227A76ED395');
        $this->addSql('ALTER TABLE booking_modification_requests DROP FOREIGN KEY FK_2CC102272A12158C');
        $this->addSql('ALTER TABLE booking_modification_requests DROP FOREIGN KEY FK_2CC1022785D7FB47');
        $this->addSql('ALTER TABLE rental_adjustment_requests DROP FOREIGN KEY FK_FDF434993301C60');
        $this->addSql('ALTER TABLE rental_adjustment_requests DROP FOREIGN KEY FK_FDF43499A76ED395');
        $this->addSql('ALTER TABLE rental_adjustment_requests DROP FOREIGN KEY FK_FDF4349985D7FB47');
        $this->addSql('DROP TABLE booking_cancellation_requests');
        $this->addSql('DROP TABLE booking_modification_requests');
        $this->addSql('DROP TABLE rental_adjustment_requests');
        $this->addSql('ALTER TABLE bookings DROP original_return_at, DROP cancelled_at');
    }
}
