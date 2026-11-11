<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111003817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact inbox replies: the last reply, when it was sent and by whom.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_messages ADD reply LONGTEXT DEFAULT NULL, ADD replied_at DATETIME DEFAULT NULL, ADD replied_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_messages ADD CONSTRAINT FK_41278201341B21F2 FOREIGN KEY (replied_by) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_41278201341B21F2 ON contact_messages (replied_by)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_messages DROP FOREIGN KEY FK_41278201341B21F2');
        $this->addSql('DROP INDEX IDX_41278201341B21F2 ON contact_messages');
        $this->addSql('ALTER TABLE contact_messages DROP reply, DROP replied_at, DROP replied_by');
    }
}
