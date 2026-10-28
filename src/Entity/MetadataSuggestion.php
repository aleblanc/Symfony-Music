<?php

namespace App\Entity;

use App\Enum\SuggestionStatus;
use App\Repository\MetadataSuggestionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Metadata proposed for a track by `app:tool:enrich` (MusicBrainz / AcoustID).
 * Applied right away when confident enough, otherwise reviewed in
 * /admin/suggestions. Keeps the values it replaced so it can be reverted.
 */
#[ORM\Entity(repositoryClass: MetadataSuggestionRepository::class)]
#[ORM\Table(name: 'metadata_suggestion')]
#[ORM\Index(name: 'metadata_suggestion_status_idx', columns: ['status'])]
#[ORM\Index(name: 'metadata_suggestion_release_idx', columns: ['release_id'])]
class MetadataSuggestion
{
    /** Keys of {@see $proposed} / {@see $previous}. */
    public const FIELDS = ['title', 'artist', 'album', 'albumArtist', 'trackNumber', 'trackTotal', 'discNumber', 'discTotal', 'year', 'genre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Music $music;

    #[ORM\Column(length: 16, enumType: SuggestionStatus::class)]
    private SuggestionStatus $status = SuggestionStatus::Pending;

    /** album-match, acoustid or search. */
    #[ORM\Column(length: 32)]
    private string $source;

    /** Match confidence, 0..1. */
    #[ORM\Column(type: 'float')]
    private float $score;

    /** @var array<string, string|int|null> */
    #[ORM\Column(type: 'json')]
    private array $proposed;

    /**
     * Values (and cover) the file had before applying, for revert.
     *
     * @var array<string, string|int|null>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $previous = null;

    /**
     * Proposed front cover (JPEG/PNG bytes), only when the track had none.
     *
     * @var resource|string|null
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private $cover = null;

    /**
     * Cover the track had before applying, restored on revert.
     *
     * @var resource|string|null
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private $previousCover = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $releaseId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $recordingId = null;

    /** @var array<string, float> */
    #[ORM\Column(type: 'json')]
    private array $scoreBreakdown = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * @param array<string, string|int|null> $proposed
     */
    public function __construct(Music $music, string $source, float $score, array $proposed)
    {
        $this->music = $music;
        $this->source = $source;
        $this->score = $score;
        $this->proposed = $proposed;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMusic(): Music
    {
        return $this->music;
    }

    public function getStatus(): SuggestionStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    /** @return array<string, string|int|null> */
    public function getProposed(): array
    {
        return $this->proposed;
    }

    public function getProposedValue(string $field): string|int|null
    {
        return $this->proposed[$field] ?? null;
    }

    /** @return array<string, string|int|null>|null */
    public function getPrevious(): ?array
    {
        return $this->previous;
    }

    /** @return ?string cover bytes */
    public function getCover(): ?string
    {
        return self::bytes($this->cover);
    }

    public function hasCover(): bool
    {
        return $this->cover !== null;
    }

    public function setCover(?string $cover): static
    {
        $this->cover = $cover;

        return $this;
    }

    /** @return ?string cover bytes */
    public function getPreviousCover(): ?string
    {
        return self::bytes($this->previousCover);
    }

    public function getReleaseId(): ?string
    {
        return $this->releaseId;
    }

    public function getRecordingId(): ?string
    {
        return $this->recordingId;
    }

    public function setMusicBrainzIds(?string $releaseId, ?string $recordingId): static
    {
        $this->releaseId = $releaseId;
        $this->recordingId = $recordingId;

        return $this;
    }

    /** @return array<string, float> */
    public function getScoreBreakdown(): array
    {
        return $this->scoreBreakdown;
    }

    /** @param array<string, float> $breakdown */
    public function setScoreBreakdown(array $breakdown): static
    {
        $this->scoreBreakdown = $breakdown;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /**
     * @param array<string, string|int|null> $previous
     */
    public function markApplied(array $previous, ?string $previousCover): void
    {
        $this->status = SuggestionStatus::Applied;
        $this->previous = $previous;
        $this->previousCover = $previousCover;
        $this->error = null;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = SuggestionStatus::Pending;
        $this->error = $error;
    }

    public function markRejected(): void
    {
        $this->status = SuggestionStatus::Rejected;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function markReverted(): void
    {
        $this->status = SuggestionStatus::Reverted;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    /**
     * Doctrine hydrates blob columns as stream resources.
     *
     * @param resource|string|null $value
     */
    private static function bytes($value): ?string
    {
        if (\is_resource($value)) {
            rewind($value);
            $value = stream_get_contents($value);

            return $value === false ? null : $value;
        }

        return \is_string($value) ? $value : null;
    }
}
