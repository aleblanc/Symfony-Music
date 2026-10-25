<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subsonic API support. Hand-written on purpose: Doctrine's generated diff
 * rebuilds the whole `music` table (copy → DROP → CREATE) on SQLite; plain
 * ADD COLUMN keeps every existing row and BLOB untouched. Existing rows are
 * filled afterwards by `app:subsonic:backfill`.
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subsonic: music album/artist keys + added/updated timestamps, starred_album, starred_artist, play_queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN album_key VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN artist_key VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN added_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN updated_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX music_album_key_idx ON music (album_key, disc_number, track_number)');
        $this->addSql('CREATE INDEX music_artist_key_idx ON music (artist_key)');
        $this->addSql('CREATE INDEX music_updated_at_idx ON music (updated_at)');

        $this->addSql('CREATE TABLE starred_album (album_key VARCHAR(16) NOT NULL, starred_at DATETIME NOT NULL, PRIMARY KEY (album_key))');
        $this->addSql('CREATE TABLE starred_artist (artist_key VARCHAR(16) NOT NULL, starred_at DATETIME NOT NULL, PRIMARY KEY (artist_key))');
        $this->addSql('CREATE TABLE play_queue (id INTEGER NOT NULL, song_ids CLOB NOT NULL, current INTEGER DEFAULT NULL, position INTEGER DEFAULT 0 NOT NULL, changed_at DATETIME NOT NULL, changed_by VARCHAR(128) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // The four music columns are left in place: dropping a column on
        // SQLite means rebuilding the table, which is not worth the risk.
        $this->addSql('DROP TABLE play_queue');
        $this->addSql('DROP TABLE starred_artist');
        $this->addSql('DROP TABLE starred_album');
        $this->addSql('DROP INDEX music_updated_at_idx');
        $this->addSql('DROP INDEX music_artist_key_idx');
        $this->addSql('DROP INDEX music_album_key_idx');
    }
}
