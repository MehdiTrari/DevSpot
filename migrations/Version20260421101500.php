<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l auteur admin des notifications pour la messagerie administrateur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD sender_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD sender_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_BF5476CA2A98155E ON notification (sender_user_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA2A98155E FOREIGN KEY (sender_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA2A98155E');
        $this->addSql('DROP INDEX IDX_BF5476CA2A98155E');
        $this->addSql('ALTER TABLE notification DROP sender_user_id');
        $this->addSql('ALTER TABLE notification DROP sender_label');
    }
}
