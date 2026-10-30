<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inflated bitrates: where each track's treble stops and its real bitrate
 * (app:cron:spectrum). Hand-written: plain ADD COLUMN, no rebuild of the
 * `music` table on SQLite.
 */
final class Version20261029200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: cutoff_hz + effective_kbps (spectrum analysis)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN cutoff_hz INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN effective_kbps INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Left in place: dropping a column on SQLite means rebuilding the table.
    }
}
