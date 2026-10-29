<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Concerts map: where each concert city is (app:cron:concert-geocode, from
 * OpenStreetMap Nominatim). Hand-written: a new table only.
 */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'city_geo: coordinates of the concert cities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE city_geo (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, place VARCHAR(320) NOT NULL, lat DOUBLE PRECISION DEFAULT NULL, lng DOUBLE PRECISION DEFAULT NULL, checked_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX city_geo_place_idx ON city_geo (place)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE city_geo');
    }
}
