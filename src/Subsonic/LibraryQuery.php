<?php

namespace App\Subsonic;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read queries behind the Subsonic API, in plain DBAL: songs are fetched
 * WITHOUT the cover BLOB (a page of 500 entities would otherwise pull ~44 MB
 * of images), and albums/artists/genres are aggregates over `music` grouped
 * by album_key / artist_key / genre.
 *
 * Every query applies the same visibility rule as
 * MusicRepository::applyVisibility(): super admins see everything, others
 * see public tracks plus their own.
 *
 * Callers pass SQL fragments ($where, $order, $join) built from constants or
 * placeholders only, never from request input.
 */
final class LibraryQuery
{
    /** Columns a "child" needs (everything except the image BLOB). */
    private const SONG_COLUMNS = 'm.id, m.file, m.title, m.artist, m.album, m.album_artist, m.genre, m.year,
        m.track_number, m.disc_number, m.duration, m.bitrate, m.sample_rate, m.bits_per_sample, m.channels,
        m.rating, m.album_key, m.artist_key, m.added_at, m.updated_at, m.bpm,
        m.replay_gain_track, m.replay_gain_track_peak, m.replay_gain_album, m.replay_gain_album_peak,
        (m.image IS NOT NULL) AS has_image';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function songs(User $viewer, string $where = '1 = 1', array $params = [], string $order = 'm.id', int $limit = 500, int $offset = 0, string $join = ''): array
    {
        [$visibility, $vParams] = $this->visibility($viewer);

        return $this->db->fetchAllAssociative(
            'SELECT ' . self::SONG_COLUMNS . " FROM music m {$join}
             WHERE ({$where}) AND {$visibility} ORDER BY {$order} LIMIT :limit OFFSET :offset",
            $params + $vParams + ['limit' => max(0, $limit), 'offset' => max(0, $offset)],
            $this->types($params),
        );
    }

    /**
     * Songs by id, in the order given (unknown or invisible ids are skipped).
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function songsByIds(User $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->songs($viewer, 'm.id IN (:ids)', ['ids' => array_values(array_unique($ids))], 'm.id', \count($ids)) as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /** @param array<string, mixed> $params */
    public function countSongs(User $viewer, string $where = '1 = 1', array $params = []): int
    {
        [$visibility, $vParams] = $this->visibility($viewer);

        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM music m WHERE ({$where}) AND {$visibility}", $params + $vParams, $this->types($params));
    }

    /**
     * Album aggregates. $order may use the aliases name, artist, year,
     * created, rating, cover_song; $join/$order may reference joined tables.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{album_key: string, name: string, artist: string, artist_key: string, song_count: int, duration: int, year: ?int, genre: ?string, created: ?string, rating: ?float, cover_song: int}>
     */
    public function albums(User $viewer, string $where = '1 = 1', array $params = [], string $order = 'name', int $limit = 500, int $offset = 0, string $join = ''): array
    {
        [$visibility, $vParams] = $this->visibility($viewer);
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.album_key,
                    COALESCE(MAX(m.album), '') AS name,
                    COALESCE(MAX(COALESCE(NULLIF(TRIM(m.album_artist), ''), m.artist)), '') AS artist,
                    MAX(m.artist_key) AS artist_key,
                    COUNT(DISTINCT m.id) AS song_count,
                    COALESCE(SUM(m.duration), 0) AS duration,
                    MAX(m.year) AS year,
                    MAX(m.genre) AS genre,
                    MIN(m.added_at) AS created,
                    AVG(NULLIF(m.rating, 0)) AS rating,
                    COALESCE(MIN(CASE WHEN m.image IS NOT NULL THEN m.id END), MIN(m.id)) AS cover_song
             FROM music m {$join}
             WHERE m.album_key IS NOT NULL AND ({$where}) AND {$visibility}
             GROUP BY m.album_key
             ORDER BY {$order}
             LIMIT :limit OFFSET :offset",
            $params + $vParams + ['limit' => max(0, $limit), 'offset' => max(0, $offset)],
            $this->types($params),
        );

        return array_map(static fn(array $r): array => [
            'album_key' => (string) $r['album_key'],
            'name' => (string) $r['name'],
            'artist' => (string) $r['artist'],
            'artist_key' => (string) $r['artist_key'],
            'song_count' => (int) $r['song_count'],
            'duration' => (int) $r['duration'],
            'year' => $r['year'] !== null ? (int) $r['year'] : null,
            'genre' => $r['genre'] !== null ? (string) $r['genre'] : null,
            'created' => $r['created'] !== null ? (string) $r['created'] : null,
            'rating' => $r['rating'] !== null ? (float) $r['rating'] : null,
            'cover_song' => (int) $r['cover_song'],
        ], $rows);
    }

    /** @return array{album_key: string, name: string, artist: string, artist_key: string, song_count: int, duration: int, year: ?int, genre: ?string, created: ?string, rating: ?float, cover_song: int}|null */
    public function album(User $viewer, string $albumKey): ?array
    {
        return $this->albums($viewer, 'm.album_key = :key', ['key' => $albumKey], 'name', 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{artist_key: string, name: string, album_count: int, cover_album: string}>
     */
    public function artists(User $viewer, string $where = '1 = 1', array $params = []): array
    {
        [$visibility, $vParams] = $this->visibility($viewer);
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.artist_key,
                    COALESCE(MAX(COALESCE(NULLIF(TRIM(m.album_artist), ''), m.artist)), '') AS name,
                    COUNT(DISTINCT m.album_key) AS album_count,
                    MAX(m.album_key) AS cover_album
             FROM music m
             WHERE m.artist_key IS NOT NULL AND ({$where}) AND {$visibility}
             GROUP BY m.artist_key
             ORDER BY name COLLATE NOCASE",
            $params + $vParams,
            $this->types($params),
        );

        return array_map(static fn(array $r): array => [
            'artist_key' => (string) $r['artist_key'],
            'name' => (string) $r['name'],
            'album_count' => (int) $r['album_count'],
            'cover_album' => (string) $r['cover_album'],
        ], $rows);
    }

    /** @return array{artist_key: string, name: string, album_count: int, cover_album: string}|null */
    public function artist(User $viewer, string $artistKey): ?array
    {
        return $this->artists($viewer, 'm.artist_key = :key', ['key' => $artistKey])[0] ?? null;
    }

    /** @return list<array{value: string, songCount: int, albumCount: int}> */
    public function genres(User $viewer): array
    {
        [$visibility, $vParams] = $this->visibility($viewer);
        $rows = $this->db->fetchAllAssociative(
            "SELECT m.genre AS value, COUNT(*) AS song_count, COUNT(DISTINCT m.album_key) AS album_count
             FROM music m WHERE m.genre IS NOT NULL AND TRIM(m.genre) <> '' AND {$visibility}
             GROUP BY m.genre ORDER BY m.genre COLLATE NOCASE",
            $vParams,
        );

        return array_map(static fn(array $r): array => [
            'value' => (string) $r['value'],
            'songCount' => (int) $r['song_count'],
            'albumCount' => (int) $r['album_count'],
        ], $rows);
    }

    /**
     * Per-user annotations for a set of songs, in 2 queries (no N+1).
     *
     * @param list<int> $songIds
     *
     * @return array{starred: array<int, true>, plays: array<int, array{count: int, last: ?string}>}
     */
    public function songContext(User $user, array $songIds): array
    {
        $ctx = ['starred' => [], 'plays' => []];
        if ($songIds === []) {
            return $ctx;
        }
        $params = ['user' => $user->getId(), 'ids' => $songIds];
        $types = ['ids' => ArrayParameterType::INTEGER];

        foreach ($this->db->fetchFirstColumn('SELECT song_id FROM favorites WHERE user_id = :user AND song_id IN (:ids)', $params, $types) as $id) {
            $ctx['starred'][(int) $id] = true;
        }
        foreach ($this->db->fetchAllAssociative('SELECT song_id, play_count, last_played FROM play_counts WHERE user_id = :user AND song_id IN (:ids)', $params, $types) as $row) {
            $ctx['plays'][(int) $row['song_id']] = ['count' => (int) $row['play_count'], 'last' => $row['last_played'] !== null ? (string) $row['last_played'] : null];
        }

        return $ctx;
    }

    /**
     * @param list<string> $albumKeys
     *
     * @return array<string, int> album_key => total plays of its songs
     */
    public function albumPlayCounts(User $user, array $albumKeys): array
    {
        if ($albumKeys === []) {
            return [];
        }

        return array_map('intval', $this->db->fetchAllKeyValue(
            'SELECT m.album_key, SUM(pc.play_count) FROM play_counts pc JOIN music m ON m.id = pc.song_id
             WHERE pc.user_id = :user AND m.album_key IN (:keys) GROUP BY m.album_key',
            ['user' => $user->getId(), 'keys' => $albumKeys],
            ['keys' => ArrayParameterType::STRING],
        ));
    }

    /** @return array<string, string> album_key => starred_at */
    public function starredAlbumKeys(): array
    {
        return array_map('strval', $this->db->fetchAllKeyValue('SELECT album_key, starred_at FROM starred_album'));
    }

    /** @return array<string, string> artist_key => starred_at */
    public function starredArtistKeys(): array
    {
        return array_map('strval', $this->db->fetchAllKeyValue('SELECT artist_key, starred_at FROM starred_artist'));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function visibility(User $viewer): array
    {
        if ($viewer->isSuperAdmin()) {
            return ['1 = 1', []];
        }

        return ['(m.is_private = 0 OR m.user_id = :viewer)', ['viewer' => $viewer->getId()]];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, ArrayParameterType>
     */
    private function types(array $params): array
    {
        $types = [];
        foreach ($params as $name => $value) {
            if (\is_array($value)) {
                $types[$name] = array_filter($value, 'is_int') === $value ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;
            }
        }

        return $types;
    }
}
