<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821201712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rhythm game: rhythm_charts, rhythm_scores, rhythm_favorites tables + users.rhythm_strikes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rhythm_charts (difficulty VARCHAR(16) NOT NULL, notes_json CLOB DEFAULT NULL, level INTEGER DEFAULT 1 NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (song_id, difficulty), CONSTRAINT FK_891522F3A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_891522F3A0BDB2F3 ON rhythm_charts (song_id)');
        $this->addSql('CREATE TABLE rhythm_favorites (sort_order INTEGER DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_F8DF660BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8DF660BA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F8DF660BA76ED395 ON rhythm_favorites (user_id)');
        $this->addSql('CREATE INDEX IDX_F8DF660BA0BDB2F3 ON rhythm_favorites (song_id)');
        $this->addSql('CREATE TABLE rhythm_scores (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, score INTEGER DEFAULT 0 NOT NULL, max_combo INTEGER DEFAULT 0 NOT NULL, perfect INTEGER DEFAULT 0 NOT NULL, great INTEGER DEFAULT 0 NOT NULL, good INTEGER DEFAULT 0 NOT NULL, bad INTEGER DEFAULT 0 NOT NULL, miss INTEGER DEFAULT 0 NOT NULL, difficulty VARCHAR(16) DEFAULT \'medium\' NOT NULL, played_at VARCHAR(255) DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, CONSTRAINT FK_4E15455AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4E15455AA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX rhythm_scores_user_idx ON rhythm_scores (user_id)');
        $this->addSql('CREATE INDEX rhythm_scores_song_idx ON rhythm_scores (song_id)');
        $this->addSql('ALTER TABLE users ADD COLUMN rhythm_strikes INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rhythm_charts');
        $this->addSql('DROP TABLE rhythm_favorites');
        $this->addSql('DROP TABLE rhythm_scores');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings, last_upload_date, daily_upload_count FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(32) DEFAULT \'user\' NOT NULL, banned BOOLEAN DEFAULT 0 NOT NULL, bio CLOB DEFAULT NULL, profile_picture BLOB DEFAULT NULL, profile_picture_type VARCHAR(50) DEFAULT NULL, profile_background BLOB DEFAULT NULL, profile_background_type VARCHAR(50) DEFAULT NULL, verified VARCHAR(20) DEFAULT \'no\' NOT NULL, backup_key VARCHAR(255) DEFAULT NULL, reset_requested BOOLEAN DEFAULT 0 NOT NULL, settings CLOB DEFAULT NULL, last_upload_date VARCHAR(10) DEFAULT NULL, daily_upload_count INTEGER DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO users (id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings, last_upload_date, daily_upload_count) SELECT id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings, last_upload_date, daily_upload_count FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX users_email_idx ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX users_artist_idx ON users (artist)');
    }
}
