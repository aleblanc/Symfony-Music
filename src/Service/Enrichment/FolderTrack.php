<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** A local file taking part in a folder-level release match. */
final readonly class FolderTrack
{
    /**
     * @param list<string> $recordingIds MusicBrainz recordings AcoustID matched for this file (empty if not fingerprinted)
     */
    public function __construct(
        public int $musicId,
        public TrackHints $hints,
        public array $recordingIds = [],
    ) {}
}
