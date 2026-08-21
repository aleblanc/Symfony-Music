<?php

namespace App\Entity;

use App\Repository\MixSongRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Join row between a mix and a track. Ported from the legacy `mix_songs`
 * table. The legacy schema declares no primary key at all (relying on
 * SQLite's implicit rowid); we use a composite PK (mix_id, song_id) instead,
 * matching the convention used for every other join table in this app
 * ({@see PlaylistSong}, {@see Favorite}, …) — in practice the generation
 * logic never inserts the same (mix, song) pair twice, so this is not a
 * behavioural change, just a stricter guarantee.
 */
#[ORM\Entity(repositoryClass: MixSongRepository::class)]
#[ORM\Table(name: 'mix_songs')]
class MixSong
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Mix::class, inversedBy: 'songs')]
    #[ORM\JoinColumn(name: 'mix_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Mix $mix = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    public function getMix(): ?Mix
    {
        return $this->mix;
    }

    public function setMix(?Mix $mix): static
    {
        $this->mix = $mix;

        return $this;
    }

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
