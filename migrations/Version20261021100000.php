<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: LRCLIB lookup time (app:lyrics:fetch) and ReplayGain track/album gain + peak';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN lyrics_checked_at INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN replay_gain_track DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN replay_gain_track_peak DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN replay_gain_album DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN replay_gain_album_peak DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music DROP COLUMN lyrics_checked_at');
        $this->addSql('ALTER TABLE music DROP COLUMN replay_gain_track');
        $this->addSql('ALTER TABLE music DROP COLUMN replay_gain_track_peak');
        $this->addSql('ALTER TABLE music DROP COLUMN replay_gain_album');
        $this->addSql('ALTER TABLE music DROP COLUMN replay_gain_album_peak');
    }
}
