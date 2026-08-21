<?php

namespace App\Entity;

use App\Repository\RhythmChartRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A cached, generated note chart for one (song, difficulty) pair. Maps onto
 * the legacy `rhythm_charts` table (composite PK song_id+difficulty, no
 * surrogate id). `notesJson` is a JSON array of note objects with the shape
 * {time, lane, isLong?, endTime?, isSwipe?, hitEndSwipe?} produced by
 * {@see \App\Service\RhythmChartGenerator}. `level` is the computed 1..999
 * difficulty rating shown before play.
 */
#[ORM\Entity(repositoryClass: RhythmChartRepository::class)]
#[ORM\Table(name: 'rhythm_charts')]
class RhythmChart
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Id]
    #[ORM\Column(name: 'difficulty', type: 'string', length: 16)]
    private string $difficulty = 'medium';

    #[ORM\Column(name: 'notes_json', type: 'text', nullable: true)]
    private ?string $notesJson = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $level = 1;

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

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

    public function getNotesJson(): ?string
    {
        return $this->notesJson;
    }

    public function setNotesJson(?string $notesJson): static
    {
        $this->notesJson = $notesJson;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }
}
