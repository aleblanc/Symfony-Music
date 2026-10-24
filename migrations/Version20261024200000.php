<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'banned_audio: fingerprints of recordings refused for a track ("not this song")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE banned_audio (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, artist VARCHAR(512) NOT NULL, title VARCHAR(512) NOT NULL, fingerprint CLOB NOT NULL, duration INTEGER NOT NULL, file VARCHAR(1024) NOT NULL, banned_at DATETIME NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE banned_audio');
    }
}
