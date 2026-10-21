<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'album_favorite: albums of the discography hearted to listen to (Favorite discography page)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE album_favorite (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, release_group_id VARCHAR(36) NOT NULL, artist VARCHAR(512) NOT NULL, title VARCHAR(512) NOT NULL, type VARCHAR(16) NOT NULL, release_date VARCHAR(10) DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_ALBUM_FAVORITE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX album_favorite_user_rg ON album_favorite (user_id, release_group_id)');
        $this->addSql('CREATE INDEX IDX_ALBUM_FAVORITE_USER ON album_favorite (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE album_favorite');
    }
}
