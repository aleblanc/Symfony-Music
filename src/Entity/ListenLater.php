<?php

namespace App\Entity;

use App\Repository\ListenLaterRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A song a user bookmarked to listen to later. Maps onto the legacy
 * `listen_later` table, which has a composite primary key (user_id, song_id)
 * and no surrogate id, plus a `sort_order` column for manual drag-and-drop
 * ordering (structurally identical to {@see Favorite}).
 */
#[ORM\Entity(repositoryClass: ListenLaterRepository::class)]
#[ORM\Table(name: 'listen_later')]
class ListenLater
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(nullable: true)]
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
