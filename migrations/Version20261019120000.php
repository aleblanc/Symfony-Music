<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: technical audio columns (format, codec, bitrate mode, sample rate, bit depth, channels, lossless)';
    }

    public function up(Schema $schema): void
    {
        // Existing rows keep NULL here; `app:scan` backfills them (rows with a
        // NULL audio_format are re-read even when the file is unchanged).
        $this->addSql('ALTER TABLE music ADD COLUMN audio_format VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN audio_codec VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN bitrate_mode VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN sample_rate INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN bits_per_sample INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN channels INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN lossless BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite >= 3.35 supports DROP COLUMN on plain, unindexed columns.
        foreach (['audio_format', 'audio_codec', 'bitrate_mode', 'sample_rate', 'bits_per_sample', 'channels', 'lossless'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE music DROP COLUMN %s', $column));
        }
    }
}
