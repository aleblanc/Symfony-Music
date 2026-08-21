<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821115952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE favorites (sort_order INTEGER DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_E46960F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E46960F5A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E46960F5A76ED395 ON favorites (user_id)');
        $this->addSql('CREATE INDEX IDX_E46960F5A0BDB2F3 ON favorites (song_id)');
        $this->addSql('CREATE TABLE history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, played_at VARCHAR(255) DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, CONSTRAINT FK_27BA704BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_27BA704BA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_27BA704BA0BDB2F3 ON history (song_id)');
        $this->addSql('CREATE INDEX history_user_idx ON history (user_id)');
        $this->addSql('CREATE TABLE music (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, file VARCHAR(1024) NOT NULL, title VARCHAR(512) DEFAULT NULL, artist VARCHAR(512) DEFAULT NULL, album VARCHAR(512) DEFAULT NULL, genre VARCHAR(255) DEFAULT NULL, year INTEGER DEFAULT NULL, duration INTEGER DEFAULT NULL, image BLOB DEFAULT NULL, last_modified INTEGER DEFAULT NULL, bitrate INTEGER DEFAULT NULL, lyrics CLOB DEFAULT NULL, is_private BOOLEAN DEFAULT 0 NOT NULL, user_id INTEGER DEFAULT NULL, CONSTRAINT FK_CD52224AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD52224A8C9F3610 ON music (file)');
        $this->addSql('CREATE INDEX IDX_CD52224AA76ED395 ON music (user_id)');
        $this->addSql('CREATE INDEX music_artist_idx ON music (artist)');
        $this->addSql('CREATE INDEX music_album_idx ON music (album)');
        $this->addSql('CREATE TABLE play_counts (play_count INTEGER DEFAULT 1 NOT NULL, last_played VARCHAR(255) DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_7A11367BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A11367BA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7A11367BA76ED395 ON play_counts (user_id)');
        $this->addSql('CREATE INDEX IDX_7A11367BA0BDB2F3 ON play_counts (song_id)');
        $this->addSql('CREATE TABLE playlist_songs (sort_order INTEGER DEFAULT NULL, added_at VARCHAR(255) DEFAULT NULL, playlist_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (playlist_id, song_id), CONSTRAINT FK_19F0A8D86BBD148 FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_19F0A8D8A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_19F0A8D86BBD148 ON playlist_songs (playlist_id)');
        $this->addSql('CREATE INDEX IDX_19F0A8D8A0BDB2F3 ON playlist_songs (song_id)');
        $this->addSql('CREATE TABLE playlists (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, public_id VARCHAR(64) NOT NULL, description CLOB DEFAULT NULL, is_private BOOLEAN DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_5E06116FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E06116FB5B48B91 ON playlists (public_id)');
        $this->addSql('CREATE INDEX IDX_5E06116FA76ED395 ON playlists (user_id)');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(32) DEFAULT \'user\' NOT NULL, banned BOOLEAN DEFAULT 0 NOT NULL, bio CLOB DEFAULT NULL, settings CLOB DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX users_email_idx ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX users_artist_idx ON users (artist)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE history');
        $this->addSql('DROP TABLE music');
        $this->addSql('DROP TABLE play_counts');
        $this->addSql('DROP TABLE playlist_songs');
        $this->addSql('DROP TABLE playlists');
        $this->addSql('DROP TABLE users');
    }
}
