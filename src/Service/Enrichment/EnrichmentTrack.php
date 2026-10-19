<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** Indexed state of one track, as loaded for an enrichment run (no cover bytes). */
final readonly class EnrichmentTrack
{
    public function __construct(
        public int $musicId,
        public string $file,
        public ?string $title,
        public ?string $artist,
        public ?string $album,
        public ?string $genre,
        public int $duration,
        public ?int $trackNumber,
        public bool $hasImage,
        /** False for siblings that only help identify the album. */
        public bool $target,
    ) {}
}
