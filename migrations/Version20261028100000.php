<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Latest Instagram posts of the artists (app:artist:posts). Hand-written:
 * plain ADD COLUMN, no table rebuild on SQLite.
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'artist_profile: posts (latest Instagram posts) + posts_checked_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artist_profile ADD COLUMN posts CLOB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE artist_profile ADD COLUMN posts_checked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN posts_checked_at');
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN posts');
    }
}
