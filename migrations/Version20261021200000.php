<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'artist_profile: discography (studio albums and EPs from MusicBrainz, null until fetched)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist_profile ADD COLUMN discography CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist_profile DROP COLUMN discography');
    }
}
