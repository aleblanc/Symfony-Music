<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\User;
use App\Subsonic\AlbumMapper;
use App\Subsonic\IdCodec;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;

/**
 * Album and song lists: getAlbumList(2) with every standard type, random
 * songs, songs by genre, starred items, top songs of an artist and
 * "similar" songs (same genre, other artists — there is no similarity
 * data, this is what feeds Tempo's continuous play).
 */
final class ListsHandler implements SubsonicHandler
{
    private const MAX_SIZE = 500;

    public function __construct(
        private readonly LibraryQuery $library,
        private readonly SongMapper $songs,
    ) {}

    public static function methods(): array
    {
        return ['getAlbumList', 'getAlbumList2', 'getRandomSongs', 'getSongsByGenre', 'getStarred', 'getStarred2',
            'getTopSongs', 'getSimilarSongs', 'getSimilarSongs2'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        return match ($method) {
            'getAlbumList' => ['albumList' => ['album' => $this->albumList($request, $user)]],
            'getAlbumList2' => ['albumList2' => ['album' => $this->albumList($request, $user)]],
            'getRandomSongs' => ['randomSongs' => ['song' => $this->randomSongs($request, $user)]],
            'getSongsByGenre' => ['songsByGenre' => ['song' => $this->songsByGenre($request, $user)]],
            'getStarred' => ['starred' => $this->starred($user)],
            'getStarred2' => ['starred2' => $this->starred($user)],
            'getTopSongs' => ['topSongs' => ['song' => $this->topSongs($request, $user)]],
            'getSimilarSongs' => ['similarSongs' => ['song' => $this->similarSongs($request, $user)]],
            'getSimilarSongs2' => ['similarSongs2' => ['song' => $this->similarSongs($request, $user)]],
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }

    /** @return list<array<string, mixed>> */
    private function albumList(SubsonicRequest $request, User $user): array
    {
        $type = $request->require('type');
        $size = $this->size($request->int('size', 10));
        $offset = max(0, $request->int('offset', 0));
        $userId = $user->getId();
        $where = '1 = 1';
        $params = [];

        switch ($type) {
            case 'alphabeticalByName':
                $order = 'name COLLATE NOCASE';
                break;
            case 'alphabeticalByArtist':
                $order = 'artist COLLATE NOCASE, year, name COLLATE NOCASE';
                break;
            case 'newest':
                $order = 'created DESC, name COLLATE NOCASE';
                break;
            case 'random':
                $order = 'RANDOM()';
                break;
            case 'starred':
                $where = 'm.album_key IN (SELECT album_key FROM starred_album)';
                $order = 'name COLLATE NOCASE';
                break;
            case 'highest':
                $where = 'm.rating > 0';
                $order = 'rating DESC, name COLLATE NOCASE';
                break;
            case 'recent':
                // Ordered by the album's latest play; correlated on the grouped key
                // so the aggregates (song count, duration) stay those of the album.
                $where = 'm.album_key IN (SELECT m2.album_key FROM history h JOIN music m2 ON m2.id = h.song_id WHERE h.user_id = :user)';
                $order = '(SELECT MAX(h.played_at) FROM history h JOIN music m2 ON m2.id = h.song_id WHERE h.user_id = :user AND m2.album_key = m.album_key) DESC';
                $params = ['user' => $userId];
                break;
            case 'frequent':
                $where = 'm.album_key IN (SELECT m2.album_key FROM play_counts pc JOIN music m2 ON m2.id = pc.song_id WHERE pc.user_id = :user)';
                $order = '(SELECT SUM(pc.play_count) FROM play_counts pc JOIN music m2 ON m2.id = pc.song_id WHERE pc.user_id = :user AND m2.album_key = m.album_key) DESC';
                $params = ['user' => $userId];
                break;
            case 'byYear':
                $from = $request->int('fromYear', 0);
                $to = $request->int('toYear', 9999);
                $where = 'm.year BETWEEN :lo AND :hi';
                $params = ['lo' => min($from, $to), 'hi' => max($from, $to)];
                $order = $from > $to ? 'year DESC, name COLLATE NOCASE' : 'year, name COLLATE NOCASE';
                break;
            case 'byGenre':
                $where = 'm.genre = :genre';
                $params = ['genre' => $request->require('genre')];
                $order = 'name COLLATE NOCASE';
                break;
            default:
                throw new SubsonicException(SubsonicException::GENERIC, "Unknown album list type: {$type}");
        }

        $rows = $this->library->albums($user, $where, $params, $order, $size, $offset);
        $starred = $this->library->starredAlbumKeys();
        $plays = $this->library->albumPlayCounts($user, array_column($rows, 'album_key'));

        return array_map(static fn(array $row): array => AlbumMapper::album($row, $starred, $plays), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function randomSongs(SubsonicRequest $request, User $user): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($genre = $request->get('genre')) !== null && $genre !== '') {
            $where[] = 'm.genre = :genre';
            $params['genre'] = $genre;
        }
        if ($request->get('fromYear') !== null) {
            $where[] = 'm.year >= :from';
            $params['from'] = $request->int('fromYear', 0);
        }
        if ($request->get('toYear') !== null) {
            $where[] = 'm.year <= :to';
            $params['to'] = $request->int('toYear', 9999);
        }

        $rows = $this->library->songs($user, implode(' AND ', $where), $params, 'RANDOM()', $this->size($request->int('size', 10)));

        return $this->songs->children($user, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function songsByGenre(SubsonicRequest $request, User $user): array
    {
        $rows = $this->library->songs(
            $user,
            'm.genre = :genre',
            ['genre' => $request->require('genre')],
            'm.album_key, COALESCE(m.disc_number, 1), m.track_number',
            $this->size($request->int('count', 10)),
            max(0, $request->int('offset', 0)),
        );

        return $this->songs->children($user, $rows);
    }

    /** @return array<string, mixed> */
    private function starred(User $user): array
    {
        $songRows = $this->library->songs(
            $user,
            'm.id IN (SELECT song_id FROM favorites WHERE user_id = :user)',
            ['user' => $user->getId()],
            '(SELECT f.sort_order FROM favorites f WHERE f.user_id = :user AND f.song_id = m.id)',
            100000,
        );

        $starredAlbums = $this->library->starredAlbumKeys();
        $albums = $starredAlbums === [] ? [] : $this->library->albums($user, 'm.album_key IN (SELECT album_key FROM starred_album)', [], 'name COLLATE NOCASE', 100000);

        $starredArtists = $this->library->starredArtistKeys();
        $artists = $starredArtists === [] ? [] : $this->library->artists($user, 'm.artist_key IN (SELECT artist_key FROM starred_artist)');

        return [
            'artist' => array_map(static fn(array $a): array => AlbumMapper::artist($a, $starredArtists), $artists),
            'album' => array_map(static fn(array $a): array => AlbumMapper::album($a, $starredAlbums), $albums),
            'song' => $this->songs->children($user, $songRows),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function topSongs(SubsonicRequest $request, User $user): array
    {
        $rows = $this->library->songs(
            $user,
            'LOWER(m.artist) = LOWER(:artist) OR LOWER(m.album_artist) = LOWER(:artist)',
            ['artist' => $request->require('artist'), 'user' => $user->getId()],
            'COALESCE((SELECT pc.play_count FROM play_counts pc WHERE pc.user_id = :user AND pc.song_id = m.id), 0) DESC, COALESCE(m.rating, 0) DESC, m.title COLLATE NOCASE',
            $this->size($request->int('count', 50)),
        );

        return $this->songs->children($user, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function similarSongs(SubsonicRequest $request, User $user): array
    {
        $decoded = IdCodec::decode($request->require('id'));
        $seed = match ($decoded['type']) {
            'song' => $this->library->songsByIds($user, [(int) $decoded['value']])[0] ?? null,
            'album' => $this->library->songs($user, 'm.album_key = :k', ['k' => $decoded['value']], 'm.id', 1)[0] ?? null,
            'artist' => $this->library->songs($user, 'm.artist_key = :k', ['k' => $decoded['value']], 'RANDOM()', 1)[0] ?? null,
            default => null,
        } ?? throw SubsonicException::notFound('Item');

        $count = $this->size($request->int('count', 50));
        $where = 'm.artist_key IS NOT :artist';
        $params = ['artist' => $seed['artist_key']];
        if ($seed['genre'] !== null && trim((string) $seed['genre']) !== '') {
            $where .= ' AND m.genre = :genre';
            $params['genre'] = $seed['genre'];
        }

        return $this->songs->children($user, $this->library->songs($user, $where, $params, 'RANDOM()', $count));
    }

    private function size(int $requested): int
    {
        return max(1, min(self::MAX_SIZE, $requested));
    }
}
