<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A MusicBrainz recording (a song performance) found by AcoustID or a search. */
final readonly class RecordingMatch
{
    /**
     * @param list<ReleaseSummary> $releases releases containing this recording (may be ids only when from AcoustID)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $artist,
        /** Seconds. */
        public ?int $duration,
        /** Confidence 0..1 (AcoustID score or MusicBrainz search score). */
        public float $score,
        public array $releases,
    ) {}

    /** @return list<string> */
    public function releaseIds(): array
    {
        return array_map(static fn(ReleaseSummary $r): string => $r->id, $this->releases);
    }
}
