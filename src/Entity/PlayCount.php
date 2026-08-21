<?php

namespace App\Entity;

use App\Repository\PlayCountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aggregated per-user play count for a track. Maps onto the legacy
 * `play_counts` table (composite PK user_id + song_id, no surrogate id).
 */
#[ORM\Entity(repositoryClass: PlayCountRepository::class)]
#[ORM\Table(name: 'play_counts')]
class PlayCount
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(name: 'play_count', options: ['default' => 1])]
    private int $playCount = 1;

    /** Legacy stores this as a TEXT timestamp. */
    #[ORM\Column(name: 'last_played', type: 'string', nullable: true)]
    private ?string $lastPlayed = null;

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

    public function getPlayCount(): int
    {
        return $this->playCount;
    }

    public function setPlayCount(int $playCount): static
    {
        $this->playCount = $playCount;

        return $this;
    }

    public function increment(): static
    {
        ++$this->playCount;

        return $this;
    }

    public function getLastPlayed(): ?string
    {
        return $this->lastPlayed;
    }

    public function touch(\DateTimeImmutable $when): static
    {
        $this->lastPlayed = $when->format('Y-m-d H:i:s');

        return $this;
    }
}
