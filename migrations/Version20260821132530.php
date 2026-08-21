<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821132530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE listen_later (sort_order INTEGER DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_595E8508A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_595E8508A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_595E8508A76ED395 ON listen_later (user_id)');
        $this->addSql('CREATE INDEX IDX_595E8508A0BDB2F3 ON listen_later (song_id)');
        $this->addSql('CREATE TABLE offline_songs (sort_order INTEGER DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_FF1B2214A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FF1B2214A0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FF1B2214A76ED395 ON offline_songs (user_id)');
        $this->addSql('CREATE INDEX IDX_FF1B2214A0BDB2F3 ON offline_songs (song_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_song_settings AS SELECT volume_multiplier, eq_bands, user_id, song_id FROM user_song_settings');
        $this->addSql('DROP TABLE user_song_settings');
        $this->addSql('CREATE TABLE user_song_settings (volume_multiplier DOUBLE PRECISION DEFAULT 1 NOT NULL, eq_bands CLOB DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_A0175BDDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A0175BDDA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_song_settings (volume_multiplier, eq_bands, user_id, song_id) SELECT volume_multiplier, eq_bands, user_id, song_id FROM __temp__user_song_settings');
        $this->addSql('DROP TABLE __temp__user_song_settings');
        $this->addSql('CREATE INDEX IDX_A0175BDDA0BDB2F3 ON user_song_settings (song_id)');
        $this->addSql('CREATE INDEX IDX_A0175BDDA76ED395 ON user_song_settings (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE listen_later');
        $this->addSql('DROP TABLE offline_songs');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user_song_settings AS SELECT volume_multiplier, eq_bands, user_id, song_id FROM user_song_settings');
        $this->addSql('DROP TABLE user_song_settings');
        $this->addSql('CREATE TABLE user_song_settings (volume_multiplier DOUBLE PRECISION DEFAULT \'1\' NOT NULL, eq_bands CLOB DEFAULT NULL, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL, PRIMARY KEY (user_id, song_id), CONSTRAINT FK_A0175BDDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A0175BDDA0BDB2F3 FOREIGN KEY (song_id) REFERENCES music (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_song_settings (volume_multiplier, eq_bands, user_id, song_id) SELECT volume_multiplier, eq_bands, user_id, song_id FROM __temp__user_song_settings');
        $this->addSql('DROP TABLE __temp__user_song_settings');
        $this->addSql('CREATE INDEX IDX_A0175BDDA76ED395 ON user_song_settings (user_id)');
        $this->addSql('CREATE INDEX IDX_A0175BDDA0BDB2F3 ON user_song_settings (song_id)');
    }
}
