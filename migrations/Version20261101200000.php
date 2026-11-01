<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops music.image: every cover is in its audio file (app:cover:migrate said
 * so) and is served from public/images/cover_cache/ by its cover_hash. Then
 * VACUUM, so the database file actually shrinks (the blobs were most of it).
 * Not transactional: VACUUM cannot run inside a transaction.
 */
final class Version20261101200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'music: drop image (cover art lives in the audio files + cover cache)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $version = (string) $this->connection->fetchOne('SELECT sqlite_version()');
        $this->abortIf(version_compare($version, '3.35.0', '<'), \sprintf('SQLite %s cannot DROP COLUMN (3.35+ needed).', $version));

        $this->addSql('ALTER TABLE music DROP COLUMN image');
        $this->addSql('VACUUM');
    }

    public function down(Schema $schema): void
    {
        // The column comes back empty: restore a backup to get the blobs back.
        $this->addSql('ALTER TABLE music ADD COLUMN image BLOB DEFAULT NULL');
    }
}
