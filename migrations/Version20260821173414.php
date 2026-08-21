<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821173414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE playlist_collaborators (playlist_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (playlist_id, user_id), CONSTRAINT FK_541AA4E16BBD148 FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_541AA4E1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_541AA4E16BBD148 ON playlist_collaborators (playlist_id)');
        $this->addSql('CREATE INDEX IDX_541AA4E1A76ED395 ON playlist_collaborators (user_id)');
        $this->addSql('CREATE TABLE playlist_invites (token VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, playlist_id INTEGER NOT NULL, PRIMARY KEY (token), CONSTRAINT FK_BC417D766BBD148 FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_BC417D766BBD148 ON playlist_invites (playlist_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__playlist_songs AS SELECT sort_order, added_at, playlist_id, song_id FROM playlist_songs');
        $this->addSql('DROP TABLE playlist_songs');
        $this->addSql('CREATE TABLE playlist_songs (sort_order INTEGER DEFAULT NULL, added_at VARCHAR(255) DEFAULT NULL, playlist_id INTEGER NOT NULL, song_id INTEGER NOT NULL, added_by INTEGER DEFAULT NULL, PRIMARY KEY (playlist_id, song_id), CONSTRAINT FK_19F0A8D86BBD148 FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_19F0A8D8A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_19F0A8D8699B6BAF FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO playlist_songs (sort_order, added_at, playlist_id, song_id) SELECT sort_order, added_at, playlist_id, song_id FROM __temp__playlist_songs');
        $this->addSql('DROP TABLE __temp__playlist_songs');
        $this->addSql('CREATE INDEX IDX_19F0A8D8A0BDB2F3 ON playlist_songs (song_id)');
        $this->addSql('CREATE INDEX IDX_19F0A8D86BBD148 ON playlist_songs (playlist_id)');
        $this->addSql('CREATE INDEX IDX_19F0A8D8699B6BAF ON playlist_songs (added_by)');
        $this->addSql('ALTER TABLE playlists ADD COLUMN is_collaborative BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE playlists ADD COLUMN play_count INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_song_settings AS SELECT volume_multiplier, eq_bands, user_id, song_id FROM user_song_settings');
        $this->addSql('DROP TABLE user_song_settings');
        $this->addSql('CREATE TABLE user_song_settings (volume_multiplier DOUBLE PRECISION DEFAULT 1 NOT NULL, eq_bands CLOB DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_A0175BDDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A0175BDDA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_song_settings (volume_multiplier, eq_bands, user_id, song_id) SELECT volume_multiplier, eq_bands, user_id, song_id FROM __temp__user_song_settings');
        $this->addSql('DROP TABLE __temp__user_song_settings');
        $this->addSql('CREATE INDEX IDX_A0175BDDA0BDB2F3 ON user_song_settings (song_id)');
        $this->addSql('CREATE INDEX IDX_A0175BDDA76ED395 ON user_song_settings (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE playlist_collaborators');
        $this->addSql('DROP TABLE playlist_invites');
        $this->addSql('CREATE TEMPORARY TABLE __temp__playlist_songs AS SELECT sort_order, added_at, playlist_id, song_id FROM playlist_songs');
        $this->addSql('DROP TABLE playlist_songs');
        $this->addSql('CREATE TABLE playlist_songs (sort_order INTEGER DEFAULT NULL, added_at VARCHAR(255) DEFAULT NULL, playlist_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (playlist_id, song_id), CONSTRAINT FK_19F0A8D86BBD148 FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_19F0A8D8A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO playlist_songs (sort_order, added_at, playlist_id, song_id) SELECT sort_order, added_at, playlist_id, song_id FROM __temp__playlist_songs');
        $this->addSql('DROP TABLE __temp__playlist_songs');
        $this->addSql('CREATE INDEX IDX_19F0A8D86BBD148 ON playlist_songs (playlist_id)');
        $this->addSql('CREATE INDEX IDX_19F0A8D8A0BDB2F3 ON playlist_songs (song_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__playlists AS SELECT id, name, public_id, description, is_private, created_at, user_id FROM playlists');
        $this->addSql('DROP TABLE playlists');
        $this->addSql('CREATE TABLE playlists (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, public_id VARCHAR(64) NOT NULL, description CLOB DEFAULT NULL, is_private BOOLEAN DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_5E06116FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO playlists (id, name, public_id, description, is_private, created_at, user_id) SELECT id, name, public_id, description, is_private, created_at, user_id FROM __temp__playlists');
        $this->addSql('DROP TABLE __temp__playlists');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E06116FB5B48B91 ON playlists (public_id)');
        $this->addSql('CREATE INDEX IDX_5E06116FA76ED395 ON playlists (user_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_song_settings AS SELECT volume_multiplier, eq_bands, user_id, song_id FROM user_song_settings');
        $this->addSql('DROP TABLE user_song_settings');
        $this->addSql('CREATE TABLE user_song_settings (volume_multiplier DOUBLE PRECISION DEFAULT \'1\' NOT NULL, eq_bands CLOB DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_A0175BDDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A0175BDDA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_song_settings (volume_multiplier, eq_bands, user_id, song_id) SELECT volume_multiplier, eq_bands, user_id, song_id FROM __temp__user_song_settings');
        $this->addSql('DROP TABLE __temp__user_song_settings');
        $this->addSql('CREATE INDEX IDX_A0175BDDA76ED395 ON user_song_settings (user_id)');
        $this->addSql('CREATE INDEX IDX_A0175BDDA0BDB2F3 ON user_song_settings (song_id)');
    }
}
