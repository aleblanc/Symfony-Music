<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cover art moves out of the database: music.cover_hash (sha1 of the cover
 * bytes) names the file of public/images/cover_cache/, written on first view.
 * Hand-written: plain ADD COLUMN, no rebuild of the `music` table on SQLite.
 * Filled by app:cover:migrate (SQLite has no sha1()); music.image stays until
 * that command reports no cover that exists only in the database.
 */
final class Version20261101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: cover_hash (cover art cached as files, not blobs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN cover_hash VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE INDEX music_cover_hash_idx ON music (cover_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX music_cover_hash_idx');
        // The column is left in place: dropping one on SQLite means rebuilding the table.
    }
}
