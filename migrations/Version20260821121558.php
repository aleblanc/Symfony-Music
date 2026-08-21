<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821121558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD COLUMN profile_picture BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN profile_picture_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN profile_background BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN profile_background_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN verified VARCHAR(20) DEFAULT \'no\' NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN backup_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN reset_requested BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, artist, password_hash, status, banned, bio, settings FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(32) DEFAULT \'user\' NOT NULL, banned BOOLEAN DEFAULT 0 NOT NULL, bio CLOB DEFAULT NULL, settings CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO users (id, email, artist, password_hash, status, banned, bio, settings) SELECT id, email, artist, password_hash, status, banned, bio, settings FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX users_email_idx ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX users_artist_idx ON users (artist)');
    }
}
