<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107153351 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rental inspections, return settlements and payment refunds';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment_refunds (id INT AUTO_INCREMENT NOT NULL, refund_type VARCHAR(30) DEFAULT \'payment_correction\' NOT NULL, amount INT NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, reason LONGTEXT NOT NULL, requested_at DATETIME NOT NULL, approved_at DATETIME DEFAULT NULL, processed_at DATETIME DEFAULT NULL, reference_number VARCHAR(120) DEFAULT \'\', customer_note LONGTEXT DEFAULT NULL, admin_note LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, payment_id INT NOT NULL, booking_id INT NOT NULL, cancellation_request_id INT DEFAULT NULL, booking_modification_id INT DEFAULT NULL, rental_settlement_id INT DEFAULT NULL, approved_by INT DEFAULT NULL, INDEX idx_refunds_booking_status (booking_id, status), INDEX idx_refunds_payment_status (payment_id, status), INDEX idx_refunds_type_status (refund_type, status), INDEX IDX_67AC34334C3A3BB (payment_id), INDEX IDX_67AC34333301C60 (booking_id), INDEX IDX_67AC3433B5C10724 (cancellation_request_id), INDEX IDX_67AC3433F746CCC0 (booking_modification_id), INDEX IDX_67AC34339A359F07 (rental_settlement_id), INDEX IDX_67AC34334EA3CB3D (approved_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rental_inspections (id INT AUTO_INCREMENT NOT NULL, inspection_type VARCHAR(20) NOT NULL, odometer INT DEFAULT 0 NOT NULL, fuel_percent INT DEFAULT 100 NOT NULL, body_condition VARCHAR(40) DEFAULT \'good\' NOT NULL, notes LONGTEXT DEFAULT NULL, damage_notes LONGTEXT DEFAULT NULL, extra_charges INT DEFAULT 0 NOT NULL, inspected_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, recorded_by INT NOT NULL, UNIQUE INDEX uniq_inspections_booking_type (booking_id, inspection_type), INDEX IDX_9FD97F1C3301C60 (booking_id), INDEX IDX_9FD97F1C82D4278B (recorded_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rental_settlements (id INT AUTO_INCREMENT NOT NULL, deposit_paid INT DEFAULT 0 NOT NULL, damage_charges INT DEFAULT 0 NOT NULL, fuel_charges INT DEFAULT 0 NOT NULL, late_charges INT DEFAULT 0 NOT NULL, other_charges INT DEFAULT 0 NOT NULL, total_deductions INT DEFAULT 0 NOT NULL, deposit_refund_amount INT DEFAULT 0 NOT NULL, outstanding_balance INT DEFAULT 0 NOT NULL, status VARCHAR(30) DEFAULT \'finalized\' NOT NULL, finalized_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, finalized_by INT NOT NULL, UNIQUE INDEX UNIQ_1E4307FE3301C60 (booking_id), INDEX idx_settlements_status (status, finalized_at), INDEX IDX_1E4307FEDCA2A522 (finalized_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC34334C3A3BB FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC34333301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC3433B5C10724 FOREIGN KEY (cancellation_request_id) REFERENCES booking_cancellation_requests (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC3433F746CCC0 FOREIGN KEY (booking_modification_id) REFERENCES booking_modification_requests (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC34339A359F07 FOREIGN KEY (rental_settlement_id) REFERENCES rental_settlements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC34334EA3CB3D FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE rental_inspections ADD CONSTRAINT FK_9FD97F1C3301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rental_inspections ADD CONSTRAINT FK_9FD97F1C82D4278B FOREIGN KEY (recorded_by) REFERENCES users (id)');
        $this->addSql('ALTER TABLE rental_settlements ADD CONSTRAINT FK_1E4307FE3301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rental_settlements ADD CONSTRAINT FK_1E4307FEDCA2A522 FOREIGN KEY (finalized_by) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC34334C3A3BB');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC34333301C60');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC3433B5C10724');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC3433F746CCC0');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC34339A359F07');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC34334EA3CB3D');
        $this->addSql('ALTER TABLE rental_inspections DROP FOREIGN KEY FK_9FD97F1C3301C60');
        $this->addSql('ALTER TABLE rental_inspections DROP FOREIGN KEY FK_9FD97F1C82D4278B');
        $this->addSql('ALTER TABLE rental_settlements DROP FOREIGN KEY FK_1E4307FE3301C60');
        $this->addSql('ALTER TABLE rental_settlements DROP FOREIGN KEY FK_1E4307FEDCA2A522');
        $this->addSql('DROP TABLE payment_refunds');
        $this->addSql('DROP TABLE rental_inspections');
        $this->addSql('DROP TABLE rental_settlements');
    }
}
