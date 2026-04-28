<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le stockage des embeddings de matching sur les profils developpeur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE developer_profile ADD matching_embedding JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE developer_profile ADD matching_embedding_dimension INT DEFAULT NULL');
        $this->addSql('ALTER TABLE developer_profile ADD matching_embedding_text_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE developer_profile ADD matching_embedding_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE developer_profile DROP matching_embedding');
        $this->addSql('ALTER TABLE developer_profile DROP matching_embedding_dimension');
        $this->addSql('ALTER TABLE developer_profile DROP matching_embedding_text_hash');
        $this->addSql('ALTER TABLE developer_profile DROP matching_embedding_updated_at');
    }
}
