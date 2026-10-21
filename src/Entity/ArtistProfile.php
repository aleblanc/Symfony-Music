<?php

namespace App\Entity;

use App\Repository\ArtistProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What is known about an artist of the library beyond its tracks: photo,
 * Wikipedia summary, links (official site, YouTube, Spotify...) and the
 * latest YouTube videos. Filled by `app:artist:fetch` (MusicBrainz →
 * Wikidata → Wikipedia) and `app:artist:videos` (YouTube RSS feed); keyed by
 * the artist name as written in the tags (music.artist).
 */
#[ORM\Entity(repositoryClass: ArtistProfileRepository::class)]
#[ORM\Table(name: 'artist_profile')]
#[ORM\UniqueConstraint(name: 'UNIQ_ARTIST_PROFILE_NAME', columns: ['name'])]
class ArtistProfile
{
    /** Identified on MusicBrainz. */
    public const FOUND = 'found';
    /** No reliable MusicBrainz match: retried only with --retry. */
    public const NOT_FOUND = 'not_found';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $status = self::NOT_FOUND;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $mbid = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $wikidataId = null;

    /** Short description, e.g. "British rock band" or a MusicBrainz disambiguation. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $wikipediaUrl = null;

    /** First paragraph of the Wikipedia article. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $youtubeChannelId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $youtubeUrl = null;

    /** @var list<array{type: string, label: string, url: string}> */
    #[ORM\Column(type: 'json')]
    private array $links = [];

    /**
     * Latest videos of the channel, newest first.
     *
     * @var list<array{id: string, title: string, published: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $videos = [];

    /**
     * Studio albums and EPs (MusicBrainz release groups), newest first, with
     * their ListenBrainz popularity (null: not read) and the track list of
     * their original edition (false: no official release, null: not read);
     * null until fetched, so app:artist:fetch completes the profiles found before.
     *
     * @var ?list<array{id: string, title: string, type: string, date: ?string, listens?: ?int, listeners?: ?int, tracklist?: array<string, mixed>|false|null}>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $discography = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $videosCheckedAt = null;

    /** Last visit of the artist page: videos published after it are "new". */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $videosSeenAt = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    public function getMbid(): ?string
    {
        return $this->mbid;
    }

    public function getWikidataId(): ?string
    {
        return $this->wikidataId;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getWikipediaUrl(): ?string
    {
        return $this->wikipediaUrl;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getYoutubeChannelId(): ?string
    {
        return $this->youtubeChannelId;
    }

    public function getYoutubeUrl(): ?string
    {
        return $this->youtubeUrl ?? ($this->youtubeChannelId !== null ? 'https://www.youtube.com/channel/' . $this->youtubeChannelId : null);
    }

    /** @return list<array{type: string, label: string, url: string}> */
    public function getLinks(): array
    {
        return $this->links;
    }

    /** @return list<array{id: string, title: string, published: string}> */
    public function getVideos(): array
    {
        return $this->videos;
    }

    /** @return ?list<array{id: string, title: string, type: string, date: ?string, listens?: ?int, listeners?: ?int, tracklist?: array<string, mixed>|false|null}> */
    public function getDiscography(): ?array
    {
        return $this->discography;
    }

    /** @param list<array{id: string, title: string, type: string, date: ?string, listens?: ?int, listeners?: ?int, tracklist?: array<string, mixed>|false|null}> $discography */
    public function setDiscography(array $discography): void
    {
        $this->discography = $discography;
    }

    /** True when the discography lacks popularity figures or track lists (fetched before them, or a service was down). */
    public function needsDiscography(): bool
    {
        return $this->discography === null || array_filter($this->discography, static fn(array $a): bool => ($a['listeners'] ?? null) === null || ($a['tracklist'] ?? null) === null) !== [];
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getVideosCheckedAt(): ?\DateTimeImmutable
    {
        return $this->videosCheckedAt;
    }

    public function getVideosSeenAt(): ?\DateTimeImmutable
    {
        return $this->videosSeenAt;
    }

    /**
     * @param list<array{type: string, label: string, url: string}> $links
     */
    public function markFound(string $mbid, ?string $wikidataId, ?string $description, ?string $imageUrl, ?string $wikipediaUrl, ?string $summary, ?string $youtubeChannelId, ?string $youtubeUrl, array $links, \DateTimeImmutable $now): void
    {
        $this->status = self::FOUND;
        $this->mbid = $mbid;
        $this->wikidataId = $wikidataId;
        $this->description = $description !== null ? mb_substr($description, 0, 255) : null;
        $this->imageUrl = $imageUrl;
        $this->wikipediaUrl = $wikipediaUrl;
        $this->summary = $summary;
        if ($youtubeChannelId !== $this->youtubeChannelId) {
            $this->videos = [];
            $this->videosCheckedAt = null;
        }
        $this->youtubeChannelId = $youtubeChannelId;
        $this->youtubeUrl = $youtubeUrl;
        $this->links = $links;
        $this->fetchedAt = $now;
    }

    public function markNotFound(\DateTimeImmutable $now): void
    {
        $this->status = self::NOT_FOUND;
        $this->fetchedAt = $now;
    }

    /**
     * @param list<array{id: string, title: string, published: string}> $videos newest first
     */
    public function setVideos(array $videos, \DateTimeImmutable $now): void
    {
        $this->videos = $videos;
        $this->videosCheckedAt = $now;
    }

    /**
     * Videos published since the last visit of the artist page (at most the
     * last 30 days, so a first fetch does not flag a whole back catalogue).
     *
     * @return list<array{id: string, title: string, published: string}>
     */
    public function newVideos(\DateTimeImmutable $now): array
    {
        $since = $now->modify('-30 days');
        if ($this->videosSeenAt !== null && $this->videosSeenAt > $since) {
            $since = $this->videosSeenAt;
        }

        return array_values(array_filter($this->videos, static fn(array $v) => new \DateTimeImmutable($v['published']) > $since));
    }

    public function markVideosSeen(\DateTimeImmutable $now): void
    {
        $this->videosSeenAt = $now;
    }

    /** The artist was renamed in the tags (`app:artist:merge`): the profile follows. */
    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
