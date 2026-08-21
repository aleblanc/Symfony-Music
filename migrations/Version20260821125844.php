<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821125844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily upload quota tracking columns to users (Feature 3: upload & metadata editor).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD COLUMN last_upload_date VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN daily_upload_count INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(32) DEFAULT \'user\' NOT NULL, banned BOOLEAN DEFAULT 0 NOT NULL, bio CLOB DEFAULT NULL, profile_picture BLOB DEFAULT NULL, profile_picture_type VARCHAR(50) DEFAULT NULL, profile_background BLOB DEFAULT NULL, profile_background_type VARCHAR(50) DEFAULT NULL, verified VARCHAR(20) DEFAULT \'no\' NOT NULL, backup_key VARCHAR(255) DEFAULT NULL, reset_requested BOOLEAN DEFAULT 0 NOT NULL, settings CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO users (id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings) SELECT id, email, artist, password_hash, status, banned, bio, profile_picture, profile_picture_type, profile_background, profile_background_type, verified, backup_key, reset_requested, settings FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX users_email_idx ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX users_artist_idx ON users (artist)');
    }
}
