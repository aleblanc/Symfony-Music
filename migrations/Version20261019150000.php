<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: spotify_id + spotify_checked_at (filled by app:spotify:match)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music ADD COLUMN spotify_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE music ADD COLUMN spotify_checked_at INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE music DROP COLUMN spotify_id');
        $this->addSql('ALTER TABLE music DROP COLUMN spotify_checked_at');
    }
}
