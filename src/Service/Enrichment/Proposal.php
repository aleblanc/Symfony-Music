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
        public ?string $releaseGroupId = null,
    ) {}

    public function withCover(?string $cover): self
    {
        return new self($this->musicId, $this->fields, $this->score, $this->source, $this->releaseId, $this->recordingId, $this->breakdown, $cover, $this->releaseGroupId);
    }

    /**
     * Storable form (cover bytes excluded: fetched again when applied).
     *
     * @return array{fields: array<string, string|int|null>, score: float, source: string, releaseId: ?string, releaseGroupId: ?string, recordingId: ?string, breakdown: array<string, float>}
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'score' => $this->score,
            'source' => $this->source,
            'releaseId' => $this->releaseId,
            'releaseGroupId' => $this->releaseGroupId,
            'recordingId' => $this->recordingId,
            'breakdown' => $this->breakdown,
        ];
    }

    /**
     * @param array<string, mixed> $data from {@see toArray()}
     */
    public static function fromArray(int $musicId, array $data): self
    {
        return new self(
            $musicId,
            \is_array($data['fields'] ?? null) ? $data['fields'] : [],
            (float) ($data['score'] ?? 0),
            (string) ($data['source'] ?? self::SOURCE_SEARCH),
            isset($data['releaseId']) ? (string) $data['releaseId'] : null,
            isset($data['recordingId']) ? (string) $data['recordingId'] : null,
            \is_array($data['breakdown'] ?? null) ? $data['breakdown'] : [],
            null,
            isset($data['releaseGroupId']) ? (string) $data['releaseGroupId'] : null,
        );
    }

    /** Same identification (recording on the same release). */
    public function key(): string
    {
        return ($this->recordingId ?? '') . '|' . ($this->releaseId ?? '') . '|' . mb_strtolower((string) ($this->fields['title'] ?? ''));
    }
}
