<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A release as listed in search results (no tracklist). */
final readonly class ReleaseSummary
{
    /**
     * @param list<string> $secondaryTypes release-group secondary types (Compilation, Live, Soundtrack...)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        public ?string $date,
        public ?string $status,
        public ?string $primaryType,
        public array $secondaryTypes,
        public int $trackCount,
        /** MusicBrainz search score, 0..1 (1 when not from a search). */
        public float $score = 1.0,
    ) {}

    /**
     * Preference used to pick "the" album of a recording: an official studio
     * album beats singles, compilations, live records and soundtracks, then
     * the oldest edition wins. Higher is better.
     */
    public function preferenceRank(): int
    {
        $rank = 0;
        $rank += $this->status === 'Official' ? 4 : 0;
        $rank += match ($this->primaryType) {
            'Album' => 8,
            'EP' => 4,
            'Single' => 2,
            default => 0,
        };
        $rank -= \count($this->secondaryTypes) * 6;

        return $rank;
    }
}
