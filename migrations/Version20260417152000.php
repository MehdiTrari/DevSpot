<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la date limite des offres et les champs de modération de profil.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_offer ADD application_deadline DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE developer_profile ADD moderated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE developer_profile ADD moderation_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE developer_profile DROP moderated_at');
        $this->addSql('ALTER TABLE developer_profile DROP moderation_reason');
        $this->addSql('ALTER TABLE job_offer DROP application_deadline');
    }
}
