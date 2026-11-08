<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Folds four one-per-parent tables into their parent, keeping their data:
 *
 * - review_photos      -> reviews.photos (JSON list of filenames)
 * - password_resets    -> users.reset_* (the newest open reset per account)
 * - rental_settlements -> bookings.settlement_* (it was already one per booking)
 * - messenger_messages -> dropped; Messenger now runs sync:// and queues nothing
 *
 * payment_refunds.rental_settlement_id goes too: only a return settlement
 * creates security-deposit refunds, so the booking already identifies it.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fold review_photos, password_resets and rental_settlements into their parent tables; drop messenger_messages';
    }

    public function up(Schema $schema): void
    {
        // review_photos -> reviews.photos
        $this->addSql('ALTER TABLE reviews ADD photos JSON DEFAULT NULL');
        $this->addSql('UPDATE reviews r SET r.photos = COALESCE((SELECT JSON_ARRAYAGG(p.filename) FROM (SELECT review_id, filename FROM review_photos ORDER BY id) p WHERE p.review_id = r.id), JSON_ARRAY())');
        $this->addSql('ALTER TABLE reviews MODIFY photos JSON NOT NULL');
        $this->addSql('ALTER TABLE review_photos DROP FOREIGN KEY FK_8200A7193E2E969B');
        $this->addSql('DROP TABLE review_photos');

        // password_resets -> users.reset_*
        $this->addSql('ALTER TABLE users ADD reset_token_hash VARCHAR(64) DEFAULT NULL, ADD reset_expires_at DATETIME DEFAULT NULL, ADD reset_requested_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9DC2A7551 ON users (reset_token_hash)');
        $this->addSql('UPDATE users u JOIN (SELECT user_id, MAX(created_at) AS requested_at FROM password_resets GROUP BY user_id) r ON r.user_id = u.id SET u.reset_requested_at = r.requested_at');
        $this->addSql('UPDATE users u JOIN password_resets p ON p.id = (SELECT p2.id FROM password_resets p2 WHERE p2.user_id = u.id AND p2.used_at IS NULL AND p2.expires_at >= NOW() ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1) SET u.reset_token_hash = p.token_hash, u.reset_expires_at = p.expires_at');
        $this->addSql('ALTER TABLE password_resets DROP FOREIGN KEY FK_9EDAFEA1A76ED395');
        $this->addSql('DROP TABLE password_resets');

        // rental_settlements -> bookings.settlement_*
        $this->addSql('ALTER TABLE bookings ADD settlement_deposit_paid INT DEFAULT 0 NOT NULL, ADD settlement_damage_charges INT DEFAULT 0 NOT NULL, ADD settlement_fuel_charges INT DEFAULT 0 NOT NULL, ADD settlement_late_charges INT DEFAULT 0 NOT NULL, ADD settlement_other_charges INT DEFAULT 0 NOT NULL, ADD settlement_total_deductions INT DEFAULT 0 NOT NULL, ADD settlement_deposit_refund_amount INT DEFAULT 0 NOT NULL, ADD settlement_outstanding_balance INT DEFAULT 0 NOT NULL, ADD settlement_status VARCHAR(30) DEFAULT NULL, ADD settlement_finalized_at DATETIME DEFAULT NULL, ADD settlement_notes LONGTEXT DEFAULT NULL, ADD settlement_finalized_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT FK_7A853C35CF6F61E6 FOREIGN KEY (settlement_finalized_by) REFERENCES users (id)');
        $this->addSql('CREATE INDEX IDX_7A853C35CF6F61E6 ON bookings (settlement_finalized_by)');
        $this->addSql('UPDATE bookings b JOIN rental_settlements s ON s.booking_id = b.id SET b.settlement_deposit_paid = s.deposit_paid, b.settlement_damage_charges = s.damage_charges, b.settlement_fuel_charges = s.fuel_charges, b.settlement_late_charges = s.late_charges, b.settlement_other_charges = s.other_charges, b.settlement_total_deductions = s.total_deductions, b.settlement_deposit_refund_amount = s.deposit_refund_amount, b.settlement_outstanding_balance = s.outstanding_balance, b.settlement_status = s.status, b.settlement_finalized_at = s.finalized_at, b.settlement_notes = s.notes, b.settlement_finalized_by = s.finalized_by');
        $this->addSql('ALTER TABLE payment_refunds DROP FOREIGN KEY FK_67AC34339A359F07');
        $this->addSql('DROP INDEX IDX_67AC34339A359F07 ON payment_refunds');
        $this->addSql('ALTER TABLE payment_refunds DROP rental_settlement_id');
        $this->addSql('ALTER TABLE rental_settlements DROP FOREIGN KEY FK_1E4307FE3301C60');
        $this->addSql('ALTER TABLE rental_settlements DROP FOREIGN KEY FK_1E4307FEDCA2A522');
        $this->addSql('DROP TABLE rental_settlements');

        // messenger_messages: nothing is queued any more (MESSENGER_TRANSPORT_DSN=sync://)
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');

        // bookings.settlement_* -> rental_settlements
        $this->addSql('CREATE TABLE rental_settlements (id INT AUTO_INCREMENT NOT NULL, deposit_paid INT DEFAULT 0 NOT NULL, damage_charges INT DEFAULT 0 NOT NULL, fuel_charges INT DEFAULT 0 NOT NULL, late_charges INT DEFAULT 0 NOT NULL, other_charges INT DEFAULT 0 NOT NULL, total_deductions INT DEFAULT 0 NOT NULL, deposit_refund_amount INT DEFAULT 0 NOT NULL, outstanding_balance INT DEFAULT 0 NOT NULL, status VARCHAR(30) DEFAULT \'finalized\' NOT NULL, finalized_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, booking_id INT NOT NULL, finalized_by INT NOT NULL, UNIQUE INDEX UNIQ_1E4307FE3301C60 (booking_id), INDEX idx_settlements_status (status, finalized_at), INDEX IDX_1E4307FEDCA2A522 (finalized_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('INSERT INTO rental_settlements (booking_id, deposit_paid, damage_charges, fuel_charges, late_charges, other_charges, total_deductions, deposit_refund_amount, outstanding_balance, status, finalized_at, notes, created_at, updated_at, finalized_by) SELECT id, settlement_deposit_paid, settlement_damage_charges, settlement_fuel_charges, settlement_late_charges, settlement_other_charges, settlement_total_deductions, settlement_deposit_refund_amount, settlement_outstanding_balance, settlement_status, settlement_finalized_at, settlement_notes, settlement_finalized_at, settlement_finalized_at, settlement_finalized_by FROM bookings WHERE settlement_finalized_at IS NOT NULL AND settlement_finalized_by IS NOT NULL');
        $this->addSql('ALTER TABLE rental_settlements ADD CONSTRAINT FK_1E4307FE3301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rental_settlements ADD CONSTRAINT FK_1E4307FEDCA2A522 FOREIGN KEY (finalized_by) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payment_refunds ADD rental_settlement_id INT DEFAULT NULL');
        $this->addSql('UPDATE payment_refunds r JOIN rental_settlements s ON s.booking_id = r.booking_id SET r.rental_settlement_id = s.id WHERE r.refund_type = \'security_deposit\'');
        $this->addSql('ALTER TABLE payment_refunds ADD CONSTRAINT FK_67AC34339A359F07 FOREIGN KEY (rental_settlement_id) REFERENCES rental_settlements (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_67AC34339A359F07 ON payment_refunds (rental_settlement_id)');
        $this->addSql('ALTER TABLE bookings DROP FOREIGN KEY FK_7A853C35CF6F61E6');
        $this->addSql('DROP INDEX IDX_7A853C35CF6F61E6 ON bookings');
        $this->addSql('ALTER TABLE bookings DROP settlement_deposit_paid, DROP settlement_damage_charges, DROP settlement_fuel_charges, DROP settlement_late_charges, DROP settlement_other_charges, DROP settlement_total_deductions, DROP settlement_deposit_refund_amount, DROP settlement_outstanding_balance, DROP settlement_status, DROP settlement_finalized_at, DROP settlement_notes, DROP settlement_finalized_by');

        // users.reset_* -> password_resets
        $this->addSql('CREATE TABLE password_resets (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_9EDAFEA1B3BC57DA (token_hash), INDEX IDX_9EDAFEA1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) SELECT id, reset_token_hash, reset_expires_at, COALESCE(reset_requested_at, NOW()) FROM users WHERE reset_token_hash IS NOT NULL AND reset_expires_at IS NOT NULL');
        $this->addSql('ALTER TABLE password_resets ADD CONSTRAINT FK_9EDAFEA1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX UNIQ_1483A5E9DC2A7551 ON users');
        $this->addSql('ALTER TABLE users DROP reset_token_hash, DROP reset_expires_at, DROP reset_requested_at');

        // reviews.photos -> review_photos
        $this->addSql('CREATE TABLE review_photos (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, review_id INT NOT NULL, INDEX IDX_8200A7193E2E969B (review_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('INSERT INTO review_photos (review_id, filename, created_at) SELECT r.id, j.filename, r.created_at FROM reviews r JOIN JSON_TABLE(r.photos, \'$[*]\' COLUMNS (n FOR ORDINALITY, filename VARCHAR(255) PATH \'$\')) j ORDER BY r.id, j.n');
        $this->addSql('ALTER TABLE review_photos ADD CONSTRAINT FK_8200A7193E2E969B FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reviews DROP photos');
    }
}
