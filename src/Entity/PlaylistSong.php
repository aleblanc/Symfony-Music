<?php

namespace App\Entity;

use App\Repository\PlaylistSongRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Join row between a playlist and a track. Maps onto the legacy
 * `playlist_songs` table (composite PK playlist_id + song_id, no surrogate id).
 */
#[ORM\Entity(repositoryClass: PlaylistSongRepository::class)]
#[ORM\Table(name: 'playlist_songs')]
class PlaylistSong
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Playlist $playlist = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    /** Legacy stores this as a TEXT timestamp with a CURRENT_TIMESTAMP default. */
    #[ORM\Column(name: 'added_at', type: 'string', nullable: true)]
    private ?string $addedAt = null;

    /** Who added this track — the owner or a collaborator. Nullable: legacy rows predate this column. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'added_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    public function __construct()
    {
        $this->addedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function setPlaylist(?Playlist $playlist): static
    {
        $this->playlist = $playlist;

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

    public function getAddedAt(): ?string
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }

    public function setAddedBy(?User $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
    }
}
