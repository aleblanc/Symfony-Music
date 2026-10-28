<?php

namespace App\Entity;

use App\Repository\ArtistProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What is known about an artist of the library beyond its tracks: photo,
 * Wikipedia summary, links (official site, YouTube, Spotify...) and the
 * latest YouTube videos. Filled by `app:cron:artist-info` (MusicBrainz →
 * Wikidata → Wikipedia) and `app:cron:artist-videos` (YouTube RSS feed); keyed by
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
    /** Videos kept per artist: the RSS feed only gives the last 15, each check adds the new ones to those already known. */
    public const MAX_VIDEOS = 60;
    /** Instagram posts kept per artist: each check adds the ones on the profile page (about 12) to those already known. */
    public const MAX_POSTS = 50;
    /** Upcoming concerts kept per artist: the soonest ones. */
    public const MAX_CONCERTS = 100;

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
     * Latest videos of the channel, newest first (up to MAX_VIDEOS, gathered over the checks).
     *
     * @var list<array{id: string, title: string, published: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $videos = [];

    /**
     * Studio albums and EPs (MusicBrainz release groups), newest first, with
     * their ListenBrainz popularity (null: not read) and the track list of
     * their original edition (false: no official release, null: not read);
     * null until fetched, so app:cron:artist-info completes the profiles found before.
     *
     * @var ?list<array{id: string, title: string, type: string, date: ?string, listens?: ?int, listeners?: ?int, tracklist?: array<string, mixed>|false|null}>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $discography = null;

    /**
     * Latest Instagram posts (shortcode, "p" or "reel", alt text of the
     * thumbnail, date decoded from the shortcode), newest first, up to MAX_POSTS.
     *
     * @var list<array{id: string, type: string, caption: ?string, published: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $posts = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postsCheckedAt = null;

    /** Songkick artist id, from MusicBrainz; '' when MusicBrainz has none, null until looked up. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $songkickId = null;

    /**
     * Upcoming concerts (Songkick), soonest first, replaced on each check, up to MAX_CONCERTS;
     * "added": when the date first showed up ('' for those of the first check).
     *
     * @var list<array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string, added: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $concerts = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $concertsCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $videosCheckedAt = null;

    /** Last visit of the artist page: videos, Instagram posts and concerts published after it are "new". */
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
        return $this->discography === null || array_filter($this->discography, static fn(array $a): bool => ($a['listeners'] ?? null) === null || ($a['tracklist'] ?? null) === null
            || (\is_array($a['tracklist']) && array_filter($a['tracklist']['tracks'], static fn(array $t): bool => !\array_key_exists('listeners', $t)) !== [])) !== [];
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
     * Adds the feed's videos to the ones already known (the feed's copy wins:
     * a renamed video gets its new title), newest first, MAX_VIDEOS kept.
     *
     * @param list<array{id: string, title: string, published: string}> $videos the feed, newest first
     */
    public function setVideos(array $videos, \DateTimeImmutable $now): void
    {
        $byId = array_column($this->videos, null, 'id');
        foreach ($videos as $video) {
            $byId[$video['id']] = $video;
        }
        $merged = array_values($byId);
        usort($merged, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));
        $this->videos = \array_slice($merged, 0, self::MAX_VIDEOS);
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

    /** The Instagram account, from the profile links ("linkinpark" for instagram.com/linkinpark/). */
    public function getInstagramHandle(): ?string
    {
        foreach ($this->links as $link) {
            if (preg_match('#^https?://(?:www\.)?instagram\.com/([A-Za-z0-9._]{1,30})/?(?:[?\#].*)?$#', $link['url'], $m) === 1
                && !\in_array(strtolower($m[1]), ['p', 'reel', 'reels', 'explore', 'stories', 'accounts'], true)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return list<array{id: string, type: string, caption: ?string, published: string}> */
    public function getPosts(): array
    {
        return $this->posts;
    }

    public function getPostsCheckedAt(): ?\DateTimeImmutable
    {
        return $this->postsCheckedAt;
    }

    /**
     * Adds the posts read on the profile page to the ones already known
     * (newest copy wins), newest first, MAX_POSTS kept.
     *
     * @param list<array{id: string, type: string, caption: ?string, published: string}> $posts
     */
    public function setPosts(array $posts, \DateTimeImmutable $now): void
    {
        $byId = array_column($this->posts, null, 'id');
        foreach ($posts as $post) {
            $byId[$post['id']] = $post;
        }
        $merged = array_values($byId);
        usort($merged, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));
        $this->posts = \array_slice($merged, 0, self::MAX_POSTS);
        $this->postsCheckedAt = $now;
    }

    /**
     * Posts published since the last visit of the artist page (at most the last 30 days), as newVideos().
     *
     * @return list<array{id: string, type: string, caption: ?string, published: string}>
     */
    public function newPosts(\DateTimeImmutable $now): array
    {
        $since = $now->modify('-30 days');
        if ($this->videosSeenAt !== null && $this->videosSeenAt > $since) {
            $since = $this->videosSeenAt;
        }

        return array_values(array_filter($this->posts, static fn(array $p) => new \DateTimeImmutable($p['published']) > $since));
    }

    public function getSongkickId(): ?string
    {
        return $this->songkickId;
    }

    public function setSongkickId(?string $songkickId): void
    {
        $this->songkickId = $songkickId;
    }

    /** @return list<array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string, added: string}> */
    public function getConcerts(): array
    {
        return $this->concerts;
    }

    public function getConcertsCheckedAt(): ?\DateTimeImmutable
    {
        return $this->concertsCheckedAt;
    }

    /**
     * Replaces the upcoming concerts; a date already known keeps when it was
     * first seen, a new one gets $now (none on the first check: the whole
     * calendar is not "new").
     *
     * @param list<array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string}> $concerts
     */
    public function setConcerts(array $concerts, \DateTimeImmutable $now): void
    {
        $known = array_column($this->concerts, 'added', 'id');
        $first = $this->concertsCheckedAt === null;
        $this->concerts = array_map(static fn (array $c): array => $c + ['added' => $known[$c['id']] ?? ($first ? '' : $now->format(\DATE_ATOM))], $concerts);
        usort($this->concerts, static fn (array $a, array $b): int => strcmp($a['date'] . ($a['time'] ?? ''), $b['date'] . ($b['time'] ?? '')));
        $this->concerts = \array_slice($this->concerts, 0, self::MAX_CONCERTS);
        $this->concertsCheckedAt = $now;
    }

    /**
     * Concerts announced since the last visit of the artist page (at most the last 30 days), as newVideos().
     *
     * @return list<array<string, mixed>>
     */
    public function newConcerts(\DateTimeImmutable $now): array
    {
        $since = $now->modify('-30 days');
        if ($this->videosSeenAt !== null && $this->videosSeenAt > $since) {
            $since = $this->videosSeenAt;
        }

        return array_values(array_filter($this->concerts, static fn(array $c) => $c['added'] !== '' && new \DateTimeImmutable($c['added']) > $since));
    }

    public function markVideosSeen(\DateTimeImmutable $now): void
    {
        $this->videosSeenAt = $now;
    }

    /** The artist was renamed in the tags (`app:tool:artist-merge`): the profile follows. */
    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
