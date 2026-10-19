<?php

namespace App\Service\Spotify;

final readonly class SpotifyMatch
{
    public function __construct(
        public SpotifyTrack $track,
        public float $score,
        /** Where the candidate came from (wikidata, artist top tracks, album "…"). */
        public string $source = '',
    ) {}
}
