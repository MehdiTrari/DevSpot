<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415143810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_offer ADD status VARCHAR(20) DEFAULT \'published\' NOT NULL');
        // Populate status from existing isActive column
        $this->addSql("UPDATE job_offer SET status = 'published' WHERE is_active = true");
        $this->addSql("UPDATE job_offer SET status = 'draft' WHERE is_active = false OR is_active IS NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE job_offer DROP status');
    }
}
