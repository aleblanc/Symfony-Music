<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A MusicBrainz release with its full tracklist. */
final readonly class ReleaseCandidate
{
    /**
     * @param list<ReleaseTrack> $tracks
     */
    public function __construct(
        public string $id,
        public string $title,
        /** Album artist credit. */
        public string $artist,
        /** Original release year (release group), falling back to this edition's year. */
        public ?int $year,
        public ?string $genre,
        public ?string $releaseGroupId,
        public array $tracks,
        public bool $isCompilation = false,
    ) {}

    public function trackCount(): int
    {
        return \count($this->tracks);
    }

    public function discCount(): int
    {
        return \count(array_unique(array_map(static fn(ReleaseTrack $t): int => $t->disc, $this->tracks))) ?: 1;
    }

    /** Number of tracks on the given disc (the "total" in "3/12"). */
    public function discTrackCount(int $disc): int
    {
        return \count(array_filter($this->tracks, static fn(ReleaseTrack $t): bool => $t->disc === $disc));
    }
}
