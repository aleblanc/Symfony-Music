<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import folder: import_item (files seen by app:import, their candidates and outcome)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, path VARCHAR(1024) NOT NULL, original_name VARCHAR(512) NOT NULL, size INTEGER NOT NULL, mtime INTEGER NOT NULL, status VARCHAR(16) NOT NULL, candidates CLOB NOT NULL, destination VARCHAR(1024) DEFAULT NULL, error CLOB DEFAULT NULL, attempted_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F237CB9EB548B0F ON import_item (path)');
        $this->addSql('CREATE INDEX import_item_status_idx ON import_item (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_item');
    }
}
