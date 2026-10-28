<?php

namespace App\Entity;

use App\Repository\ImportItemRepository;
use App\Service\Enrichment\Proposal;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file of the import folder that `app:import:files` looked at: its identification
 * candidates (for the web page when none was sure enough), and what became
 * of it. Size + mtime let re-runs skip files already tried unless they
 * changed (tags fixed, file replaced).
 */
#[ORM\Entity(repositoryClass: ImportItemRepository::class)]
#[ORM\Table(name: 'import_item')]
#[ORM\Index(name: 'import_item_status_idx', columns: ['status'])]
class ImportItem
{
    /** Candidates stored, waiting for a choice in the web page. */
    public const PENDING = 'pending';
    /** Nothing plausible found. */
    public const UNMATCHED = 'unmatched';
    /** Identified, but tagging or moving failed (see error). */
    public const FAILED = 'failed';
    /** Tagged and filed into the library (see destination). */
    public const IMPORTED = 'imported';
    /** Same recording already in the library: moved to the duplicates folder. */
    public const DUPLICATE = 'duplicate';
    /** Set aside from the web page ("not now"): skipped by later runs until it changes or --retry. */
    public const SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Current absolute path in the import folder. */
    #[ORM\Column(length: 1024, unique: true)]
    private string $path;

    /** File name as found, before any renaming. */
    #[ORM\Column(length: 512)]
    private string $originalName;

    #[ORM\Column]
    private int $size;

    #[ORM\Column]
    private int $mtime;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    /**
     * Best first, {@see Proposal::toArray()} each.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $candidates = [];

    /** Where the file went (imported or duplicate). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $destination = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $attemptedAt;

    public function __construct(string $path, int $size, int $mtime)
    {
        $this->path = $path;
        $this->originalName = basename($path);
        $this->size = $size;
        $this->mtime = $mtime;
        $this->attemptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Already tried in this exact state: no point asking the remote services
     * again. Failed files are always retried (the cause — network, a writer
     * limitation — is usually fixed in between); skipped ones wait for --retry.
     */
    public function isUnchanged(int $size, int $mtime): bool
    {
        return $this->size === $size && $this->mtime === $mtime
            && \in_array($this->status, [self::PENDING, self::UNMATCHED, self::SKIPPED], true);
    }

    /**
     * @param list<Proposal> $candidates best first
     */
    public function recordAttempt(int $size, int $mtime, array $candidates, ?string $error = null): void
    {
        $this->size = $size;
        $this->mtime = $mtime;
        $this->candidates = array_map(static fn(Proposal $p): array => $p->toArray(), $candidates);
        $this->error = $error;
        $this->status = $error !== null ? self::FAILED : ($candidates === [] ? self::UNMATCHED : self::PENDING);
        $this->attemptedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<Proposal>
     */
    public function getCandidates(): array
    {
        return array_map(fn(array $c): Proposal => Proposal::fromArray((int) $this->id, $c), $this->candidates);
    }

    public function markImported(string $destination): void
    {
        $this->status = self::IMPORTED;
        $this->destination = $destination;
        $this->error = null;
        $this->attemptedAt = new \DateTimeImmutable();
    }

    public function markDuplicate(string $destination): void
    {
        $this->status = self::DUPLICATE;
        $this->destination = $destination;
        $this->attemptedAt = new \DateTimeImmutable();
    }

    public function markSkipped(): void
    {
        $this->status = self::SKIPPED;
        $this->attemptedAt = new \DateTimeImmutable();
    }

    /** A choice from the web page failed: stays pending, with the reason. */
    public function markChoiceFailed(string $error): void
    {
        $this->error = $error;
        $this->attemptedAt = new \DateTimeImmutable();
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
