<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow keeping admin action logs when users are deleted by setting FK onDelete SET NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_action_log DROP CONSTRAINT FK_7AFB50006352511C');
        $this->addSql('ALTER TABLE admin_action_log DROP CONSTRAINT FK_7AFB50006C066AFE');
        $this->addSql('ALTER TABLE admin_action_log ALTER admin_user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE admin_action_log ADD CONSTRAINT FK_7AFB50006352511C FOREIGN KEY (admin_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_action_log ADD CONSTRAINT FK_7AFB50006C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_action_log DROP CONSTRAINT FK_7AFB50006352511C');
        $this->addSql('ALTER TABLE admin_action_log DROP CONSTRAINT FK_7AFB50006C066AFE');
        $this->addSql('ALTER TABLE admin_action_log ADD CONSTRAINT FK_7AFB50006352511C FOREIGN KEY (admin_user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_action_log ADD CONSTRAINT FK_7AFB50006C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_action_log ALTER admin_user_id SET NOT NULL');
    }
}
