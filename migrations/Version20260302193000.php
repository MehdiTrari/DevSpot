<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portfolio_generated_at to developer_profile to persist portfolio generation status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE developer_profile ADD portfolio_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE developer_profile DROP portfolio_generated_at');
    }
}
