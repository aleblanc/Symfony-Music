<?php

namespace App\Service\Lyrics;

/**
 * Lyrics of one LRCLIB record chosen for a library track.
 */
final readonly class LyricsMatch
{
    public function __construct(
        public int $lrclibId,
        public string $artist,
        public string $title,
        public ?string $synced,
        public ?string $plain,
        public bool $instrumental,
        public float $score,
    ) {}

    /** What gets stored: synced LRC when there is one, else plain text. */
    public function text(): ?string
    {
        return $this->synced ?? $this->plain;
    }
}
