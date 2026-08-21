<?php

namespace App\Entity;

use App\Repository\RhythmScoreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recorded rhythm-game run. Maps onto the legacy `rhythm_scores` table
 * (surrogate id + per-run judgment counters + difficulty). Append-only,
 * same lifecycle as {@see History}.
 */
#[ORM\Entity(repositoryClass: RhythmScoreRepository::class)]
#[ORM\Table(name: 'rhythm_scores')]
#[ORM\Index(name: 'rhythm_scores_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'rhythm_scores_song_idx', columns: ['song_id'])]
class RhythmScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $score = 0;

    #[ORM\Column(name: 'max_combo', type: 'integer', options: ['default' => 0])]
    private int $maxCombo = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $perfect = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $great = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $good = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bad = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $miss = 0;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'medium'])]
    private string $difficulty = 'medium';

    /** Legacy stores this as a TEXT timestamp (see {@see History}). */
    #[ORM\Column(name: 'played_at', type: 'string', nullable: true)]
    private ?string $playedAt = null;

    public function __construct()
    {
        $this->playedAt = new \DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getMaxCombo(): int
    {
        return $this->maxCombo;
    }

    public function setMaxCombo(int $maxCombo): static
    {
        $this->maxCombo = $maxCombo;

        return $this;
    }

    public function getPerfect(): int
    {
        return $this->perfect;
    }

    public function setPerfect(int $perfect): static
    {
        $this->perfect = $perfect;

        return $this;
    }

    public function getGreat(): int
    {
        return $this->great;
    }

    public function setGreat(int $great): static
    {
        $this->great = $great;

        return $this;
    }

    public function getGood(): int
    {
        return $this->good;
    }

    public function setGood(int $good): static
    {
        $this->good = $good;

        return $this;
    }

    public function getBad(): int
    {
        return $this->bad;
    }

    public function setBad(int $bad): static
    {
        $this->bad = $bad;

        return $this;
    }

    public function getMiss(): int
    {
        return $this->miss;
    }

    public function setMiss(int $miss): static
    {
        $this->miss = $miss;

        return $this;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getPlayedAt(): ?string
    {
        return $this->playedAt;
    }
}
