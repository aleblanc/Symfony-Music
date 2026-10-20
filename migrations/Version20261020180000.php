<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artist pages: artist_profile (photo, Wikipedia, links, latest YouTube videos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artist_profile (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(512) NOT NULL, status VARCHAR(16) NOT NULL, mbid VARCHAR(36) DEFAULT NULL, wikidata_id VARCHAR(16) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, image_url CLOB DEFAULT NULL, wikipedia_url CLOB DEFAULT NULL, summary CLOB DEFAULT NULL, youtube_channel_id VARCHAR(64) DEFAULT NULL, youtube_url CLOB DEFAULT NULL, links CLOB NOT NULL, videos CLOB NOT NULL, fetched_at DATETIME DEFAULT NULL, videos_checked_at DATETIME DEFAULT NULL, videos_seen_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ARTIST_PROFILE_NAME ON artist_profile (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artist_profile');
    }
}
