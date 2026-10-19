<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** One track of a MusicBrainz release. */
final readonly class ReleaseTrack
{
    public function __construct(
        public int $disc,
        public int $position,
        public string $title,
        public string $artist,
        /** Seconds, null when MusicBrainz has no length. */
        public ?int $duration,
        public ?string $recordingId,
    ) {}
}
