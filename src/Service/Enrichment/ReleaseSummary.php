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
        /** @var list<string> medium formats ("CD", "Digital Media", "12\" Vinyl"...) */
        public array $formats = [],
    ) {}

    /**
     * Bootlegs, live records, interviews and DJ mixes are never the album a
     * library file belongs to, unless the folder says it is live.
     */
    public function isUnwanted(bool $allowLive): bool
    {
        return self::unwanted($this->status, $this->secondaryTypes, $allowLive);
    }

    /**
     * @param list<string> $secondaryTypes
     */
    public static function unwanted(?string $status, array $secondaryTypes, bool $allowLive): bool
    {
        if ($status === 'Bootleg' || array_intersect($secondaryTypes, ['Interview', 'DJ-mix']) !== []) {
            return true;
        }

        return !$allowLive && \in_array('Live', $secondaryTypes, true);
    }

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
        // Library files are CD rips or downloads: vinyl/cassette editions
        // split albums into sides/discs with their own numbering.
        if ($this->formats !== [] && preg_grep('/vinyl|cassette|^7"|^10"|^12"/i', $this->formats) === $this->formats) {
            $rank -= 3;
        }

        return $rank;
    }
}
