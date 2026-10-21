<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\PlayCount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Music>
 */
class MusicRepository extends ServiceEntityRepository
{
    /**
     * Sort keys accepted by `?sort=` on the library listing, ported from the
     * legacy `sort_map` in the `get_view_ids` action. Each entry maps to the
     * ORDER BY clauses applied below.
     */
    public const DEFAULT_SORT = 'artist_asc';

    public const SORT_KEYS = [
        'artist_asc', 'artist_desc',
        'album_asc', 'album_desc',
        'year_asc', 'year_desc',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Music::class);
    }

    public function save(Music $music, bool $flush = true): void
    {
        $this->getEntityManager()->persist($music);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByFile(string $file): ?Music
    {
        return $this->findOneBy(['file' => $file]);
    }

    /**
     * Visibility-aware paginated library listing. Private tracks are only
     * returned to their owner (mirrors the legacy public-index filtering).
     *
     * @return Paginator<Music>
     */
    public function paginateLibrary(?User $viewer, ?string $query, int $page = 1, int $perPage = 60, string $sort = self::DEFAULT_SORT): Paginator
    {
        $qb = $this->createQueryBuilder('m');
        $this->applySort($qb, $sort);

        $this->applyVisibility($qb, $viewer);

        if ($query !== null && trim($query) !== '') {
            $qb->andWhere('m.title LIKE :q OR m.artist LIKE :q OR m.album LIKE :q OR m.genre LIKE :q')
                ->setParameter('q', '%' . trim($query) . '%');
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * Applies one of the SORT_KEYS ORDER BY clauses, falling back to the
     * default artist/album/title ordering for an unrecognised key.
     */
    private function applySort(\Doctrine\ORM\QueryBuilder $qb, string $sort): void
    {
        switch ($sort) {
            case 'artist_desc':
                $qb->orderBy('m.artist', 'DESC')->addOrderBy('m.album', 'ASC')->addOrderBy('m.title', 'ASC');
                break;
            case 'album_asc':
                $qb->orderBy('m.album', 'ASC')->addOrderBy('m.title', 'ASC');
                break;
            case 'album_desc':
                $qb->orderBy('m.album', 'DESC')->addOrderBy('m.title', 'ASC');
                break;
            case 'year_asc':
                $qb->orderBy('m.year', 'ASC')->addOrderBy('m.album', 'ASC')->addOrderBy('m.title', 'ASC');
                break;
            case 'year_desc':
                $qb->orderBy('m.year', 'DESC')->addOrderBy('m.album', 'ASC')->addOrderBy('m.title', 'ASC');
                break;
            case 'artist_asc':
            default:
                $qb->orderBy('m.artist', 'ASC')->addOrderBy('m.album', 'ASC')->addOrderBy('m.title', 'ASC');
                break;
        }
    }

    /**
     * @return Music[]
     */
    public function findByArtist(string $artist, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.artist = :artist')
            ->setParameter('artist', $artist)
            ->orderBy('m.album', 'ASC')
            ->addOrderBy('m.title', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Music[]
     */
    public function findByAlbum(string $album, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.album = :album')
            ->setParameter('album', $album)
            ->orderBy('m.title', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct artists with their track counts, for the "Artists" browse view.
     *
     * @return array<array{artist: string, cnt: int}>
     */
    public function listArtists(?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.artist AS artist, COUNT(m.id) AS cnt')
            ->andWhere('m.artist IS NOT NULL')
            ->groupBy('m.artist')
            ->orderBy('m.artist', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Music[]
     */
    public function findByGenre(string $genre, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.genre = :genre')
            ->setParameter('genre', $genre)
            ->orderBy('m.artist', 'ASC')
            ->addOrderBy('m.album', 'ASC')
            ->addOrderBy('m.title', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Music[]
     */
    public function findByYear(int $year, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.year = :year')
            ->setParameter('year', $year)
            ->orderBy('m.artist', 'ASC')
            ->addOrderBy('m.album', 'ASC')
            ->addOrderBy('m.title', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct genres with their track counts, for the "Genres" browse view.
     *
     * @return array<array{genre: string, cnt: int}>
     */
    public function listGenres(?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.genre AS genre, COUNT(m.id) AS cnt')
            ->andWhere('m.genre IS NOT NULL')
            ->andWhere("m.genre != ''")
            ->groupBy('m.genre')
            ->orderBy('m.genre', 'ASC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct years with their track counts, for the "Years" browse view.
     * Ordered newest first, matching the legacy `get_years` action.
     *
     * @return array<array{year: int, cnt: int}>
     */
    public function listYears(?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.year AS year, COUNT(m.id) AS cnt')
            ->andWhere('m.year IS NOT NULL')
            ->andWhere('m.year > 0')
            ->groupBy('m.year')
            ->orderBy('m.year', 'DESC');

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * One cover per album (the album's first track that has artwork), grouped
     * by artist or by year, at most $perGroup albums each: the mosaics of the
     * Artists, Years and Genres tiles.
     *
     * @param 'artist'|'year'|'genre' $field
     *
     * @return array<string, list<int>> group value (artist, genre, or year as a string) => track ids
     */
    public function albumCoverIds(string $field, ?User $viewer, int $perGroup = 4): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select("m.{$field} AS grp", 'MIN(m.id) AS id')
            ->andWhere("m.{$field} IS NOT NULL")
            ->andWhere('m.image IS NOT NULL')
            ->groupBy("m.{$field}")->addGroupBy('m.album')
            ->orderBy("m.{$field}", 'ASC')->addOrderBy('MIN(m.year)', 'DESC')->addOrderBy('m.album', 'ASC');
        $this->applyVisibility($qb, $viewer);

        $covers = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $group = (string) $row['grp'];
            if (\count($covers[$group] ?? []) < $perGroup) {
                $covers[$group][] = (int) $row['id'];
            }
        }

        return $covers;
    }

    /**
     * Every artist of the library (whole library: admin commands) with its
     * album titles.
     *
     * @return array<string, list<string>>
     */
    public function artistAlbums(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.artist AS artist', 'm.album AS album')
            ->andWhere("m.artist IS NOT NULL AND m.artist != ''")
            ->groupBy('m.artist')->addGroupBy('m.album')
            ->orderBy('m.artist', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $artists = [];
        foreach ($rows as $row) {
            $artists[(string) $row['artist']] ??= [];
            if (\is_string($row['album']) && $row['album'] !== '') {
                $artists[(string) $row['artist']][] = $row['album'];
            }
        }

        return $artists;
    }

    /**
     * Number of tracks per artist name, whole library (for app:artist:merge).
     *
     * @return array<string, int>
     */
    public function artistTrackCounts(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('m')
            ->select('m.artist AS artist', 'COUNT(m.id) AS n')
            ->andWhere("m.artist IS NOT NULL AND m.artist != ''")
            ->groupBy('m.artist')
            ->getQuery()
            ->getArrayResult() as $row) {
            $counts[(string) $row['artist']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Tracks whose artist or album artist is one of these names.
     *
     * @param list<string> $names
     *
     * @return list<Music>
     */
    public function findByArtistOrAlbumArtist(array $names): array
    {
        if ($names === []) {
            return [];
        }

        /** @var list<Music> */
        return $this->createQueryBuilder('m')
            ->andWhere('m.artist IN (:names) OR m.albumArtist IN (:names)')
            ->setParameter('names', $names)
            ->orderBy('m.file', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Albums of one artist for the artist page tiles: cover track, year,
     * track count; newest first.
     *
     * @return list<array{album: string, year: ?int, cnt: int, coverId: ?int}>
     */
    public function albumsOfArtist(string $artist, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.album AS album', 'MAX(m.year) AS year', 'COUNT(m.id) AS cnt', 'MIN(CASE WHEN m.image IS NOT NULL THEN m.id ELSE :none END) AS coverId')
            ->setParameter('none', \PHP_INT_MAX)
            ->andWhere('m.artist = :artist')->setParameter('artist', $artist)
            ->andWhere("m.album IS NOT NULL AND m.album != ''")
            ->groupBy('m.album')
            ->orderBy('year', 'DESC')->addOrderBy('m.album', 'ASC');
        $this->applyVisibility($qb, $viewer);

        return array_map(static fn(array $r) => [
            'album' => (string) $r['album'],
            'year' => $r['year'] !== null ? (int) $r['year'] : null,
            'cnt' => (int) $r['cnt'],
            'coverId' => (int) $r['coverId'] !== \PHP_INT_MAX ? (int) $r['coverId'] : null,
        ], array_values($qb->getQuery()->getArrayResult()));
    }

    /**
     * Most recently indexed tracks. Music has no creation timestamp, but ids
     * are autoincremented as the scan/upload inserts rows, so the highest ids
     * are the newest additions.
     *
     * @return Music[]
     */
    public function findRecentlyAdded(?User $viewer, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit);

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * Ids to process with app:spotify:match, oldest first. Not visibility
     * filtered: this is a library maintenance task.
     *
     * - default: never looked up
     * - $retryUnmatched: also tracks looked up without a match
     * - $all: every track (re-match)
     * - $matchedOnly: tracks that already have a Spotify id (tag writing)
     *
     * @return int[]
     */
    public function spotifyCandidateIds(bool $retryUnmatched = false, bool $all = false, bool $matchedOnly = false, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('m')->select('m.id')->orderBy('m.id', 'ASC');

        if ($matchedOnly) {
            $qb->andWhere('m.spotifyId IS NOT NULL');
        } elseif (!$all) {
            $qb->andWhere($retryUnmatched ? 'm.spotifyId IS NULL' : 'm.spotifyCheckedAt IS NULL');
        }
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    /**
     * Track ids for app:lyrics:fetch, oldest first. By default only tracks
     * without lyrics that were never looked up; $retry adds those LRCLIB did
     * not know before; $all also re-fetches tracks that already have lyrics.
     *
     * @return list<int>
     */
    public function lyricsCandidateIds(bool $retry = false, bool $all = false, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('m')->select('m.id')->orderBy('m.id', 'ASC');

        if (!$all) {
            $qb->andWhere("m.lyrics IS NULL OR m.lyrics = ''");
            if (!$retry) {
                $qb->andWhere('m.lyricsCheckedAt IS NULL');
            }
        }
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return array_values(array_map('intval', $qb->getQuery()->getSingleColumnResult()));
    }

    /**
     * Every track as plain rows for app:enrich (no cover bytes hydrated).
     * Not visibility filtered: this is a library maintenance task.
     *
     * @return list<array{id: int, file: string, title: ?string, artist: ?string, album: ?string, genre: ?string, duration: ?int, trackNumber: ?int, hasImage: bool, enrichedAt: ?\DateTimeImmutable}>
     */
    public function findEnrichmentRows(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id, m.file, m.title, m.artist, m.album, m.genre, m.duration, m.trackNumber, m.enrichedAt')
            ->addSelect('CASE WHEN m.image IS NULL THEN 0 ELSE 1 END AS hasImage')
            ->orderBy('m.file', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $nullableString = static fn(mixed $v): ?string => \is_string($v) ? $v : null;
        $nullableInt = static fn(mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'file' => (string) $r['file'],
            'title' => $nullableString($r['title']),
            'artist' => $nullableString($r['artist']),
            'album' => $nullableString($r['album']),
            'genre' => $nullableString($r['genre']),
            'duration' => $nullableInt($r['duration']),
            'trackNumber' => $nullableInt($r['trackNumber']),
            'hasImage' => (int) $r['hasImage'] === 1,
            'enrichedAt' => $r['enrichedAt'] instanceof \DateTimeImmutable ? $r['enrichedAt'] : null,
        ], $rows));
    }

    public function countAll(?User $viewer): int
    {
        $qb = $this->createQueryBuilder('m')->select('COUNT(m.id)');
        $this->applyVisibility($qb, $viewer);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Song browser of the rhythm-game hub: optional text search over
     * title/artist/album plus the hub's sort modes. Deliberately NOT
     * visibility-filtered (the hub has always listed every indexed track).
     * "random" is a deterministic pseudo-shuffle (Knuth multiplicative hash
     * of the id) so infinite-scroll pagination never repeats rows.
     *
     * @return Music[]
     */
    public function rhythmList(?string $query, string $sort, int $limit, int $offset, ?string $artist = null): array
    {
        $qb = $this->createQueryBuilder('m');

        if ($artist !== null && $artist !== '') {
            $qb->andWhere('m.artist = :artist')->setParameter('artist', $artist);
        }

        if ($query !== null && $query !== '') {
            $qb->andWhere('m.title LIKE :q OR m.artist LIKE :q OR m.album LIKE :q')
                ->setParameter('q', '%' . addcslashes($query, '%_\\') . '%');
        }

        if ($sort === 'trending') {
            $qb->leftJoin(PlayCount::class, 'pc', 'WITH', 'pc.song = m')
                ->addSelect('COALESCE(SUM(pc.playCount), 0) AS HIDDEN totalPlays')
                ->groupBy('m.id')
                ->orderBy('totalPlays', 'DESC')
                ->addOrderBy('m.id', 'DESC');
        } elseif ($sort === 'title_asc') {
            $qb->orderBy('m.title', 'ASC')->addOrderBy('m.id', 'ASC');
        } elseif ($sort === 'artist_asc') {
            $qb->orderBy('m.artist', 'ASC')->addOrderBy('m.title', 'ASC');
        } elseif ($sort === 'random') {
            $qb->addSelect('MOD(m.id * 2654435761, 4294967296) AS HIDDEN shuffleKey')
                ->orderBy('shuffleKey', 'ASC');
        } else { // id_desc (newest)
            $qb->orderBy('m.id', 'DESC');
        }

        return $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Artist directory of the rhythm-game hub: distinct artist names with
     * their track count and a sample song id (used for the card cover).
     * Ported (much simplified) from the legacy `get_rhythm_artists` — the
     * per-player score stats and follower counts are out of scope here.
     *
     * @return list<array{name: string, count: int, sample: int}>
     */
    public function rhythmArtists(?string $query, string $sort, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.artist AS name', 'COUNT(m.id) AS cnt', 'MAX(m.id) AS sample')
            ->andWhere("m.artist IS NOT NULL AND m.artist <> ''")
            ->groupBy('m.artist');

        if ($query !== null && $query !== '') {
            $qb->andWhere('m.artist LIKE :q')
                ->setParameter('q', '%' . addcslashes($query, '%_\\') . '%');
        }

        if ($sort === 'name_desc') {
            $qb->orderBy('m.artist', 'DESC');
        } elseif ($sort === 'count_desc') {
            $qb->orderBy('cnt', 'DESC')->addOrderBy('m.artist', 'ASC');
        } else {
            $qb->orderBy('m.artist', 'ASC');
        }

        $rows = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getScalarResult();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r['name'], 'count' => (int) $r['cnt'], 'sample' => (int) $r['sample']];
        }

        return $out;
    }

    /**
     * Tracks ordered by total play count (all users combined), descending.
     * Ported from the legacy `get_trending` action.
     *
     * @return Music[]
     */
    public function trending(?User $viewer, int $limit = 60, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin(PlayCount::class, 'pc', 'WITH', 'pc.song = m')
            ->addSelect('COALESCE(SUM(pc.playCount), 0) AS HIDDEN totalPlays')
            ->groupBy('m.id')
            ->orderBy('totalPlays', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyVisibility($qb, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * Visibility-aware ids matching optional artist/genre/year filters, minus
     * any excluded ids. Building block shared by the "discover" shelves and
     * radio track selection (all of which pick a random subset server-side in
     * PHP rather than relying on a SQL `RANDOM()` function, to stay within
     * plain QueryBuilder usage).
     *
     * @param int[] $excludeIds
     *
     * @return int[]
     */
    public function visibleIds(
        ?User $viewer,
        ?string $artist = null,
        ?string $genre = null,
        array $excludeIds = [],
    ): array {
        $qb = $this->createQueryBuilder('m')->select('m.id');
        $this->applyVisibility($qb, $viewer);

        if ($artist !== null) {
            $qb->andWhere('m.artist = :artist')->setParameter('artist', $artist);
        }
        if ($genre !== null) {
            $qb->andWhere('m.genre = :genre')->setParameter('genre', $genre);
        }
        if ($excludeIds !== []) {
            $qb->andWhere('m.id NOT IN (:excludeIds)')->setParameter('excludeIds', $excludeIds);
        }

        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Visibility-aware ids sharing the seed track's genre OR artist (an "OR",
     * unlike {@see visibleIds()} which ANDs its filters). Ported from the
     * legacy `get_radio_tracks` action.
     *
     * @return int[]
     */
    public function radioCandidateIds(?User $viewer, ?string $artist, ?string $genre, int $excludeId): array
    {
        $qb = $this->createQueryBuilder('m')->select('m.id');
        $this->applyVisibility($qb, $viewer);
        $qb->andWhere('m.id != :excludeId')->setParameter('excludeId', $excludeId);

        $orConditions = [];
        if ($genre !== null && $genre !== '') {
            $orConditions[] = 'm.genre = :genre';
            $qb->setParameter('genre', $genre);
        }
        if ($artist !== null && $artist !== '') {
            $orConditions[] = 'm.artist = :artist';
            $qb->setParameter('artist', $artist);
        }
        if ($orConditions !== []) {
            $qb->andWhere(implode(' OR ', $orConditions));
        }

        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @param int[] $ids
     *
     * @return Music[] Hydrated entities in the same order as $ids (entries
     *                  that no longer exist are silently skipped), matching
     *                  the reordering trick used by the legacy `get_queue_songs`
     *                  action.
     */
    public function hydrateByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * A random sample of $limit visible tracks, optionally excluding a set of
     * ids (typically the viewer's history, for "discover something new").
     *
     * @param int[] $excludeIds
     *
     * @return Music[]
     */
    public function randomVisible(?User $viewer, int $limit, array $excludeIds = []): array
    {
        $ids = $this->visibleIds($viewer, excludeIds: $excludeIds);
        shuffle($ids);

        return $this->hydrateByIds(\array_slice($ids, 0, $limit));
    }

    /**
     * Random albums (grouped by album+artist, as raw strings — this app does
     * not split multi-artist "feat." credits, see Feature 2 notes) from a
     * random sample of visible tracks. Ported (simplified) from the album
     * grouping block of `get_explore`/`get_recommendations`: the legacy also
     * tracks a `total_plays` figure per album purely for a UI badge, which is
     * dropped here to keep the query simple.
     *
     * @param int[] $excludeIds
     *
     * @return array<int, array{album: string, artist: string, id: int, songCount: int}>
     */
    public function randomAlbums(?User $viewer, int $limit, array $excludeIds = [], ?string $artist = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id', 'm.album', 'm.artist')
            ->andWhere("m.album != 'Unknown Album'")
            ->andWhere("m.album != ''")
            ->andWhere('m.album IS NOT NULL');
        $this->applyVisibility($qb, $viewer);

        if ($artist !== null) {
            $qb->andWhere('m.artist = :artist')->setParameter('artist', $artist);
        }
        if ($excludeIds !== []) {
            $qb->andWhere('m.id NOT IN (:excludeIds)')->setParameter('excludeIds', $excludeIds);
        }

        $rows = $qb->getQuery()->getScalarResult();
        shuffle($rows);
        $rows = \array_slice($rows, 0, 200);

        $albums = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['album'] . ':::' . $row['artist']);
            if (!isset($albums[$key])) {
                $albums[$key] = ['album' => $row['album'], 'artist' => $row['artist'], 'id' => (int) $row['id'], 'songCount' => 1];
            } else {
                ++$albums[$key]['songCount'];
            }
        }

        $albums = array_values($albums);
        shuffle($albums);

        return \array_slice($albums, 0, $limit);
    }

    /**
     * Distinct artist names from a random sample of visible tracks, each
     * resolved to a representative track id. Ported (simplified) from the
     * "Discover Artists" block of `get_explore`/`get_recommendations` — the
     * legacy also splits multi-artist "feat." strings into separate entries,
     * which this app does not do (see Feature 2 notes: artist stays a raw
     * string).
     *
     * @param int[] $excludeIds
     *
     * @return array<int, array{name: string, id: int}>
     */
    public function distinctArtistNames(?User $viewer, int $limit, array $excludeIds = []): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id', 'm.artist')
            ->andWhere("m.artist != 'Unknown Artist'")
            ->andWhere("m.artist != ''")
            ->andWhere('m.artist IS NOT NULL');
        $this->applyVisibility($qb, $viewer);

        if ($excludeIds !== []) {
            $qb->andWhere('m.id NOT IN (:excludeIds)')->setParameter('excludeIds', $excludeIds);
        }

        $rows = $qb->getQuery()->getScalarResult();
        shuffle($rows);

        $seen = [];
        $artists = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['artist']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $artists[] = ['name' => $row['artist'], 'id' => (int) $row['id']];
            if (\count($artists) >= $limit) {
                break;
            }
        }

        return $artists;
    }

    public function isVisibleTo(Music $song, ?User $viewer): bool
    {
        if (!$song->isPrivate()) {
            return true;
        }
        if ($viewer === null) {
            return false;
        }
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return $song->getOwner() !== null && $song->getOwner()->getId() === $viewer->getId();
    }

    /**
     * Distinct artist/genre/year seed values across the *whole* library
     * (deliberately unfiltered by visibility, matching the legacy `get_mixes`
     * generation query which pulls from the entire `music` table).
     *
     * @return array<int, array{type: string, value: string}>
     */
    public function distinctSeedValues(): array
    {
        $seeds = [];

        $artists = $this->createQueryBuilder('m')
            ->select('DISTINCT m.artist AS value')
            ->andWhere("m.artist != 'Unknown Artist'")
            ->andWhere("m.artist != ''")
            ->andWhere('m.artist IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        foreach ($artists as $row) {
            $seeds[] = ['type' => 'artist', 'value' => (string) $row['value']];
        }

        $genres = $this->createQueryBuilder('m')
            ->select('DISTINCT m.genre AS value')
            ->andWhere("m.genre != 'Unknown Genre'")
            ->andWhere("m.genre != ''")
            ->andWhere('m.genre IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        foreach ($genres as $row) {
            $seeds[] = ['type' => 'genre', 'value' => (string) $row['value']];
        }

        $years = $this->createQueryBuilder('m')
            ->select('DISTINCT m.year AS value')
            ->andWhere('m.year IS NOT NULL')
            ->andWhere('m.year > 0')
            ->getQuery()
            ->getScalarResult();
        foreach ($years as $row) {
            $seeds[] = ['type' => 'year', 'value' => (string) $row['value']];
        }

        return $seeds;
    }

    /**
     * Ids matching one mix seed. Unfiltered by visibility (see
     * {@see distinctSeedValues()}).
     *
     * @return int[]
     */
    public function idsMatchingSeed(string $type, string $value): array
    {
        $qb = $this->createQueryBuilder('m')->select('m.id');

        match ($type) {
            'artist' => $qb->andWhere('m.artist = :value')->setParameter('value', $value),
            'genre' => $qb->andWhere('m.genre = :value')->setParameter('value', $value),
            'year' => $qb->andWhere('m.year = :value')->setParameter('value', (int) $value),
            default => throw new \InvalidArgumentException("Unknown seed type \"{$type}\""),
        };

        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * One track id matching the seed that has cover art, for the mix's
     * thumbnail (falls back to null so the caller can pick any track id).
     */
    public function oneImageIdMatchingSeed(string $type, string $value): ?int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id')
            ->andWhere('m.image IS NOT NULL')
            ->setMaxResults(20);

        match ($type) {
            'artist' => $qb->andWhere('m.artist = :value')->setParameter('value', $value),
            'genre' => $qb->andWhere('m.genre = :value')->setParameter('value', $value),
            'year' => $qb->andWhere('m.year = :value')->setParameter('value', (int) $value),
            default => throw new \InvalidArgumentException("Unknown seed type \"{$type}\""),
        };

        $rows = $qb->getQuery()->getScalarResult();
        if ($rows === []) {
            return null;
        }

        $row = $rows[array_rand($rows)];

        return (int) $row['id'];
    }

    /**
     * Random ids from the whole library (unfiltered), used to pad a mix out
     * to its target track count when a seed did not have enough matches.
     *
     * @return int[]
     */
    public function randomAnyIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->getQuery()
            ->getScalarResult();

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        shuffle($ids);

        return \array_slice($ids, 0, $limit);
    }

    /**
     * Restricts a query to tracks the viewer is allowed to see: public tracks,
     * plus the viewer's own private tracks. Super admins see everything.
     */
    private function applyVisibility(\Doctrine\ORM\QueryBuilder $qb, ?User $viewer): void
    {
        if ($viewer !== null && $viewer->isSuperAdmin()) {
            return;
        }

        if ($viewer !== null) {
            $qb->andWhere('m.isPrivate = false OR m.owner = :viewer')
                ->setParameter('viewer', $viewer);
        } else {
            $qb->andWhere('m.isPrivate = false');
        }
    }

    /**
     * Best-effort matching of an imported playlist entry back to a library
     * track, ported from the legacy `import_playlist` fallback chain: exact
     * title+artist+album, then title+artist, then a loose title/artist
     * `LIKE`, then a match on the file's basename. Simplification shared
     * with Features 2/10: the legacy `match_artist()` SQL function (splits
     * multi-artist "feat." strings) is not ported, so a title match against
     * a track credited to multiple artists may not resolve — documented, not
     * fixed, consistent with prior features.
     *
     * @param array<string, mixed> $entry Raw imported song entry (title/artist/album/filename keys).
     */
    public function matchImportedSong(array $entry): ?Music
    {
        $title = isset($entry['title']) ? trim((string) $entry['title']) : '';
        $artist = isset($entry['artist']) ? trim((string) $entry['artist']) : '';
        $album = isset($entry['album']) ? trim((string) $entry['album']) : '';
        $filename = basename(str_replace('\\', '/', isset($entry['filename']) ? (string) $entry['filename'] : ''));

        if ($title !== '' && $artist !== '') {
            if ($album !== '') {
                $found = $this->createQueryBuilder('m')
                    ->andWhere('LOWER(m.title) = LOWER(:title)')
                    ->andWhere('LOWER(m.artist) = LOWER(:artist)')
                    ->andWhere('LOWER(m.album) = LOWER(:album)')
                    ->setParameter('title', $title)
                    ->setParameter('artist', $artist)
                    ->setParameter('album', $album)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                if ($found !== null) {
                    return $found;
                }
            }

            $found = $this->createQueryBuilder('m')
                ->andWhere('LOWER(m.title) = LOWER(:title)')
                ->andWhere('LOWER(m.artist) = LOWER(:artist)')
                ->setParameter('title', $title)
                ->setParameter('artist', $artist)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($found !== null) {
                return $found;
            }

            $found = $this->createQueryBuilder('m')
                ->andWhere('LOWER(m.title) LIKE LOWER(:title)')
                ->andWhere('LOWER(m.artist) LIKE LOWER(:artist)')
                ->setParameter('title', '%' . $title . '%')
                ->setParameter('artist', '%' . $artist . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($found !== null) {
                return $found;
            }
        }

        if ($filename !== '') {
            $found = $this->createQueryBuilder('m')
                ->andWhere('m.file LIKE :suffixSlash OR m.file LIKE :suffixBackslash')
                ->setParameter('suffixSlash', '%/' . $filename)
                ->setParameter('suffixBackslash', '%\\' . $filename)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($found !== null) {
                return $found;
            }

            $found = $this->createQueryBuilder('m')
                ->andWhere('m.file LIKE :any')
                ->setParameter('any', '%' . $filename . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Whole library (no visibility filter: admin stats) grouped by the
     * fields QualityStats needs, bitrate rounded to kb/s.
     *
     * @return list<array{format: ?string, lossless: ?bool, kbps: ?int, sampleRate: ?int, bitsPerSample: ?int, n: int}>
     */
    public function qualityRows(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.audioFormat AS format', 'm.lossless AS lossless', '((m.bitrate + 500) / 1000) AS kbps', 'm.sampleRate AS sampleRate', 'm.bitsPerSample AS bitsPerSample', 'COUNT(m.id) AS n')
            ->groupBy('format')->addGroupBy('lossless')->addGroupBy('kbps')->addGroupBy('sampleRate')->addGroupBy('bitsPerSample')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'format' => \is_string($r['format']) ? $r['format'] : null,
            'lossless' => $r['lossless'] === null ? null : (bool) $r['lossless'],
            'kbps' => $r['kbps'] === null ? null : (int) $r['kbps'],
            'sampleRate' => $r['sampleRate'] === null ? null : (int) $r['sampleRate'],
            'bitsPerSample' => $r['bitsPerSample'] === null ? null : (int) $r['bitsPerSample'],
            'n' => (int) $r['n'],
        ], array_values($rows));
    }

    /**
     * Lossy tracks, lowest bitrate first: of one format and bitrate range
     * (kb/s, bounds included), or of every format under $maxKbps.
     *
     * @return list<array{id: int, title: ?string, artist: ?string, album: ?string, file: string, format: string, lossless: ?bool, bitrate: ?int, bitrateMode: ?string}>
     */
    public function lossyTracksByBitrate(?string $format, ?int $minKbps, ?int $maxKbps, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id', 'm.title', 'm.artist', 'm.album', 'm.file', 'm.audioFormat AS format', 'm.lossless', 'm.bitrate', 'm.bitrateMode', 'm.spotifyId')
            ->andWhere('m.audioFormat IS NOT NULL')
            ->andWhere('m.lossless IS NULL OR m.lossless = false')
            ->andWhere("m.audioFormat NOT IN ('flac', 'alac', 'wav', 'aiff')")
            ->orderBy('m.bitrate', 'ASC')->addOrderBy('m.artist', 'ASC')->addOrderBy('m.id', 'ASC')
            ->setMaxResults($limit);
        if ($format !== null) {
            $qb->andWhere('m.audioFormat = :format')->setParameter('format', $format);
        }
        if ($minKbps !== null) {
            $qb->andWhere('(m.bitrate + 500) / 1000 >= :min')->setParameter('min', $minKbps);
        }
        if ($maxKbps !== null) {
            $qb->andWhere('(m.bitrate + 500) / 1000 <= :max')->setParameter('max', $maxKbps);
        }

        /** @var list<array{id: int, title: ?string, artist: ?string, album: ?string, file: string, format: string, lossless: ?bool, bitrate: ?int, bitrateMode: ?string, spotifyId: ?string}> */
        return $qb->getQuery()->getArrayResult();
    }
}
