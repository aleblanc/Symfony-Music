<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Upcoming concerts of the artists (app:artist:concerts, from Songkick).
 * Hand-written: plain ADD COLUMN, no table rebuild on SQLite.
 */
final class Version20261028150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'artist_profile: songkick_id, concerts (upcoming concerts) + concerts_checked_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist_profile ADD COLUMN songkick_id VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE artist_profile ADD COLUMN concerts CLOB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE artist_profile ADD COLUMN concerts_checked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN concerts_checked_at');
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN concerts');
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN songkick_id');
    }
}
