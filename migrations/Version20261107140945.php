<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107140945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer payments, rental documents, notifications, favorites, reviews and review photos';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_documents (id INT AUTO_INCREMENT NOT NULL, document_type VARCHAR(40) NOT NULL, document_number VARCHAR(120) DEFAULT \'\', expiry_date DATE DEFAULT NULL, filename VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, admin_notes LONGTEXT DEFAULT NULL, verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, verified_by INT DEFAULT NULL, INDEX idx_documents_user_status (user_id, status), UNIQUE INDEX uniq_documents_user_type (user_id, document_type), INDEX IDX_94D8A92A76ED395 (user_id), INDEX IDX_94D8A9260D6A0BF (verified_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE favorites (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, vehicle_id INT NOT NULL, UNIQUE INDEX uniq_favorites_user_vehicle (user_id, vehicle_id), INDEX IDX_E46960F5A76ED395 (user_id), INDEX IDX_E46960F5545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) DEFAULT \'system\' NOT NULL, title VARCHAR(160) NOT NULL, message LONGTEXT NOT NULL, is_read TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, booking_id INT DEFAULT NULL, INDEX idx_notifications_user_read (user_id, is_read, created_at), INDEX IDX_6000B0D3A76ED395 (user_id), INDEX IDX_6000B0D33301C60 (booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payments (id INT AUTO_INCREMENT NOT NULL, payment_type VARCHAR(30) NOT NULL, method VARCHAR(40) NOT NULL, amount INT NOT NULL, transaction_reference VARCHAR(120) DEFAULT \'\', proof_filename VARCHAR(255) DEFAULT \'\', status VARCHAR(20) DEFAULT \'pending\' NOT NULL, notes LONGTEXT DEFAULT NULL, paid_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, rental_adjustment_id INT DEFAULT NULL, booking_modification_id INT DEFAULT NULL, user_id INT NOT NULL, recorded_by INT DEFAULT NULL, INDEX idx_payments_booking_status (booking_id, status), INDEX idx_payments_adjustment (rental_adjustment_id, status), INDEX idx_payments_modification (booking_modification_id, status), INDEX IDX_65D29B323301C60 (booking_id), INDEX IDX_65D29B325E1F81F (rental_adjustment_id), INDEX IDX_65D29B32F746CCC0 (booking_modification_id), INDEX IDX_65D29B32A76ED395 (user_id), INDEX IDX_65D29B3282D4278B (recorded_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review_photos (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, review_id INT NOT NULL, INDEX IDX_8200A7193E2E969B (review_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reviews (id INT AUTO_INCREMENT NOT NULL, overall INT NOT NULL, cleanliness INT NOT NULL, comfort INT NOT NULL, vehicle_condition INT NOT NULL, pickup_experience INT NOT NULL, customer_support INT NOT NULL, title VARCHAR(160) NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, user_id INT NOT NULL, vehicle_id INT NOT NULL, UNIQUE INDEX UNIQ_6970EB0F3301C60 (booking_id), INDEX idx_reviews_vehicle (vehicle_id, status), INDEX IDX_6970EB0FA76ED395 (user_id), INDEX IDX_6970EB0F545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_documents ADD CONSTRAINT FK_94D8A92A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_documents ADD CONSTRAINT FK_94D8A9260D6A0BF FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D33301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B323301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B325E1F81F FOREIGN KEY (rental_adjustment_id) REFERENCES rental_adjustment_requests (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B32F746CCC0 FOREIGN KEY (booking_modification_id) REFERENCES booking_modification_requests (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B32A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B3282D4278B FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE review_photos ADD CONSTRAINT FK_8200A7193E2E969B FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F3301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_documents DROP FOREIGN KEY FK_94D8A92A76ED395');
        $this->addSql('ALTER TABLE customer_documents DROP FOREIGN KEY FK_94D8A9260D6A0BF');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5A76ED395');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5545317D1');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D33301C60');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B323301C60');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B325E1F81F');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B32F746CCC0');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B32A76ED395');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B3282D4278B');
        $this->addSql('ALTER TABLE review_photos DROP FOREIGN KEY FK_8200A7193E2E969B');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0F3301C60');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0FA76ED395');
        $this->addSql('ALTER TABLE reviews DROP FOREIGN KEY FK_6970EB0F545317D1');
        $this->addSql('DROP TABLE customer_documents');
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE review_photos');
        $this->addSql('DROP TABLE reviews');
    }
}
