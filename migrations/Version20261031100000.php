<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spectrum analysis: whether the treble cutoff is an encoder's wall (sharp) or
 * a natural roll-off. Hand-written: plain ADD COLUMN, no rebuild of the
 * `music` table on SQLite. Inflated files are sharp by definition; the other
 * analysed tracks stay unknown (null) and app:cron:spectrum analyses them again.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: cutoff_sharp (encoder wall or natural roll-off)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN cutoff_sharp BOOLEAN DEFAULT NULL');
        $this->addSql('UPDATE music SET cutoff_sharp = 1 WHERE effective_kbps IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Left in place: dropping a column on SQLite means rebuilding the table.
    }
}
