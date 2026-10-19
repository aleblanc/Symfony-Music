<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/** Metadata proposed for one track, before it is stored as a suggestion. */
final readonly class Proposal
{
    public const SOURCE_ALBUM = 'album-match';
    public const SOURCE_ACOUSTID = 'acoustid';
    public const SOURCE_SEARCH = 'search';

    /**
     * @param array<string, string|int|null> $fields keys of {@see \App\Entity\MetadataSuggestion::FIELDS}
     * @param array<string, float> $breakdown
     */
    public function __construct(
        public int $musicId,
        public array $fields,
        public float $score,
        public string $source,
        public ?string $releaseId,
        public ?string $recordingId,
        public array $breakdown = [],
        /** Front cover bytes, only when the track has none. */
        public ?string $cover = null,
    ) {}
}
