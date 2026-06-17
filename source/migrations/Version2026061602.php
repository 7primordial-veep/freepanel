<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version2026061602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add disk_quota_project_id, last_disk_usage_mb, last_disk_check_at to site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD COLUMN disk_quota_project_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN last_disk_usage_mb INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN last_disk_check_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site DROP COLUMN disk_quota_project_id');
        $this->addSql('ALTER TABLE site DROP COLUMN last_disk_usage_mb');
        $this->addSql('ALTER TABLE site DROP COLUMN last_disk_check_at');
    }
}
