<?php

namespace App\Entity;

use App\Repository\PlaylistCollaboratorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Join row granting a non-owner user the ability to add/remove tracks on a
 * collaborative playlist. Ported from the legacy `playlist_collaborators`
 * table (composite PK, no surrogate id) populated by `manage_collaborators`
 * (direct add) or by accepting a `playlist_invites` link.
 */
#[ORM\Entity(repositoryClass: PlaylistCollaboratorRepository::class)]
#[ORM\Table(name: 'playlist_collaborators')]
class PlaylistCollaborator
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Playlist::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Playlist $playlist = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function setPlaylist(?Playlist $playlist): static
    {
        $this->playlist = $playlist;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}
