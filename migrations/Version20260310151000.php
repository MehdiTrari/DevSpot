<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260310151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Improve read performance with indexes on public portfolio listing and contact lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_dev_profile_public_generated_order ON developer_profile (is_public, portfolio_generated_at DESC, updated_at DESC)');
        $this->addSql('CREATE INDEX idx_contact_message_profile_lower_email ON contact_message (developer_profile_id, LOWER(recruiter_email))');
        $this->addSql('CREATE INDEX idx_contact_message_profile_created_order ON contact_message (developer_profile_id, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_dev_profile_public_generated_order');
        $this->addSql('DROP INDEX idx_contact_message_profile_lower_email');
        $this->addSql('DROP INDEX idx_contact_message_profile_created_order');
    }
}
