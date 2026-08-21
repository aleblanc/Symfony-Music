<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821221626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rhythm Phase 2: music.bpm + music.beat_grid_json, rhythm_charts.generator_version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN bpm DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN beat_grid_json CLOB DEFAULT NULL');
        // SQLite adds a column via table rebuild (data preserved); composite PK kept.
        $this->addSql('CREATE TEMPORARY TABLE __temp__rhythm_charts AS SELECT difficulty, notes_json, level, song_id FROM rhythm_charts');
        $this->addSql('DROP TABLE rhythm_charts');
        $this->addSql('CREATE TABLE rhythm_charts (difficulty VARCHAR(16) NOT NULL, notes_json CLOB DEFAULT NULL, level INTEGER DEFAULT 1 NOT NULL, song_id INTEGER NOT NULL, generator_version INTEGER DEFAULT 1 NOT NULL, PRIMARY KEY (song_id, difficulty), CONSTRAINT FK_891522F3A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO rhythm_charts (difficulty, notes_json, level, song_id) SELECT difficulty, notes_json, level, song_id FROM __temp__rhythm_charts');
        $this->addSql('DROP TABLE __temp__rhythm_charts');
        $this->addSql('CREATE INDEX IDX_891522F3A0BDB2F3 ON rhythm_charts (song_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop the two music columns (SQLite: table rebuild).
        $this->addSql('CREATE TEMPORARY TABLE __temp__music AS SELECT id, file, title, artist, album, genre, year, duration, image, last_modified, bitrate, lyrics, is_private, user_id FROM music');
        $this->addSql('DROP TABLE music');
        $this->addSql('CREATE TABLE music (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, file VARCHAR(1024) NOT NULL, title VARCHAR(512) DEFAULT NULL, artist VARCHAR(512) DEFAULT NULL, album VARCHAR(512) DEFAULT NULL, genre VARCHAR(255) DEFAULT NULL, year INTEGER DEFAULT NULL, duration INTEGER DEFAULT NULL, image BLOB DEFAULT NULL, last_modified INTEGER DEFAULT NULL, bitrate INTEGER DEFAULT NULL, lyrics CLOB DEFAULT NULL, is_private BOOLEAN DEFAULT 0 NOT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_CD52224AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO music (id, file, title, artist, album, genre, year, duration, image, last_modified, bitrate, lyrics, is_private, user_id) SELECT id, file, title, artist, album, genre, year, duration, image, last_modified, bitrate, lyrics, is_private, user_id FROM __temp__music');
        $this->addSql('DROP TABLE __temp__music');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD52224A8C9F3610 ON music (file)');
        $this->addSql('CREATE INDEX IDX_CD52224AA76ED395 ON music (user_id)');
        $this->addSql('CREATE INDEX music_artist_idx ON music (artist)');
        $this->addSql('CREATE INDEX music_album_idx ON music (album)');
        // Drop generator_version, restoring the composite-PK rhythm_charts.
        $this->addSql('CREATE TEMPORARY TABLE __temp__rhythm_charts AS SELECT difficulty, notes_json, level, song_id FROM rhythm_charts');
        $this->addSql('DROP TABLE rhythm_charts');
        $this->addSql('CREATE TABLE rhythm_charts (difficulty VARCHAR(16) NOT NULL, notes_json CLOB DEFAULT NULL, level INTEGER DEFAULT 1 NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (song_id, difficulty), CONSTRAINT FK_891522F3A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO rhythm_charts (difficulty, notes_json, level, song_id) SELECT difficulty, notes_json, level, song_id FROM __temp__rhythm_charts');
        $this->addSql('DROP TABLE __temp__rhythm_charts');
        $this->addSql('CREATE INDEX IDX_891522F3A0BDB2F3 ON rhythm_charts (song_id)');
    }
}
