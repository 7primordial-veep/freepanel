<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Docker container columns to the site table (nullable so existing
 * rows migrate cleanly).
 */
final class Version4 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add docker_image / docker_port / docker_env / docker_volumes / docker_container_name to site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD COLUMN docker_image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN docker_port INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN docker_env CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN docker_volumes CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN docker_container_name VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
    }
}
