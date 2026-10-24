<?php

namespace App\Service\Ban;

use App\Entity\Music;

/**
 * Dispatched by BanList once a track's file has been set aside: a listener
 * that puts another file of the same track back in the library (an earlier
 * version, say) sets $restoredFile, and the track's row is kept pointing at it
 * instead of being removed.
 */
final class TrackBannedEvent
{
    public ?string $restoredFile = null;

    public function __construct(
        public readonly Music $song,
        /** Where the banned file was in the library. */
        public readonly string $originalFile,
    ) {}
}
