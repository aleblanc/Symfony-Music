<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** Result of scoring one release against a folder of files. */
final readonly class AlbumMatch
{
    /**
     * @param array<int, ReleaseTrack> $assignments music id => aligned release track
     * @param array<string, float> $breakdown score components, for the review UI
     */
    public function __construct(
        public ReleaseCandidate $release,
        public float $score,
        public array $assignments,
        public array $breakdown,
    ) {}
}
