<?php

namespace App\Entity;

use App\Repository\RhythmFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rhythm-game-specific favorite, distinct from the general {@see Favorite}
 * list. Maps onto the legacy `rhythm_favorites` table (composite user+song
 * PK + manual `sort_order`), structurally identical to {@see ListenLater}.
 */
#[ORM\Entity(repositoryClass: RhythmFavoriteRepository::class)]
#[ORM\Table(name: 'rhythm_favorites')]
class RhythmFavorite
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(name: 'sort_order', nullable: true)]
    private ?int $sortOrder = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

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
