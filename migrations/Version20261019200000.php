<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Metadata enrichment: music album artist / track / disc / enriched_at, metadata_suggestion table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN album_artist VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN track_number INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN disc_number INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN enriched_at DATETIME DEFAULT NULL');

        $this->addSql('CREATE TABLE metadata_suggestion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(32) NOT NULL, score DOUBLE PRECISION NOT NULL, proposed CLOB NOT NULL, previous CLOB DEFAULT NULL, cover BLOB DEFAULT NULL, previous_cover BLOB DEFAULT NULL, release_id VARCHAR(36) DEFAULT NULL, recording_id VARCHAR(36) DEFAULT NULL, score_breakdown CLOB NOT NULL, error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, music_id INTEGER NOT NULL, CONSTRAINT FK_C06D5554399BBB13 FOREIGN KEY (music_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C06D5554399BBB13 ON metadata_suggestion (music_id)');
        $this->addSql('CREATE INDEX metadata_suggestion_status_idx ON metadata_suggestion (status)');
        $this->addSql('CREATE INDEX metadata_suggestion_release_idx ON metadata_suggestion (release_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE metadata_suggestion');
        foreach (['album_artist', 'track_number', 'disc_number', 'enriched_at'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE music DROP COLUMN %s', $column));
        }
    }
}
