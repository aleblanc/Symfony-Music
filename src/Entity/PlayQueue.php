<?php

namespace App\Entity;

use App\Repository\PlayQueueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The saved play queue (Subsonic savePlayQueue/getPlayQueue), so playback can
 * resume on another device. Single-user app: one row, id 1.
 */
#[ORM\Entity(repositoryClass: PlayQueueRepository::class)]
#[ORM\Table(name: 'play_queue')]
class PlayQueue
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $songIds = [];

    #[ORM\Column(nullable: true)]
    private ?int $current = null;

    /** Position in the current track, in milliseconds. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    /** Subsonic client name (`c` parameter) that saved the queue. */
    #[ORM\Column(length: 128)]
    private string $changedBy = '';

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<int> $songIds
     */
    public function replace(array $songIds, ?int $current, int $position, string $changedBy): void
    {
        $this->songIds = $songIds;
        $this->current = $current;
        $this->position = max(0, $position);
        $this->changedBy = mb_substr($changedBy, 0, 128);
        $this->changedAt = new \DateTimeImmutable();
    }

    /** @return list<int> */
    public function getSongIds(): array
    {
        return $this->songIds;
    }

    public function getCurrent(): ?int
    {
        return $this->current;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function getChangedBy(): string
    {
        return $this->changedBy;
    }
}
