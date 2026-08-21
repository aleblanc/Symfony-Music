<?php

namespace App\Entity;

use App\Repository\PlaylistInviteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use-token shareable invite link that lets whoever holds it join a
 * playlist as a collaborator. Ported from the legacy `playlist_invites`
 * table; the token itself is the primary key (no surrogate id), matching the
 * legacy schema.
 */
#[ORM\Entity(repositoryClass: PlaylistInviteRepository::class)]
#[ORM\Table(name: 'playlist_invites')]
class PlaylistInvite
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private ?string $token = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Playlist $playlist = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= new \DateTimeImmutable();
    }
}
