<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\User;
use App\Subsonic\AlbumMapper;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;

/**
 * search3 / search2. An empty query (or `""`) returns the whole library,
 * paged with *Count/*Offset: that is how Symfonium and Tempo sync.
 *
 * Extension (ours): `changedSince=<epoch ms>` keeps only songs updated after
 * that instant (Music::updatedAt), for incremental sync.
 */
final class SearchHandler implements SubsonicHandler
{
    // Clients sync the whole library through search3 with big pages
    // (Substreamer: 10000 albums / 5000 songs) and advance the offset by the
    // size they ASKED for: capping lower would silently skip the rest.
    private const MAX_COUNT = 10000;

    public function __construct(
        private readonly LibraryQuery $library,
        private readonly SongMapper $songs,
    ) {}

    public static function methods(): array
    {
        return ['search2', 'search3'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        $query = trim((string) $request->get('query'));
        $query = trim($query, '"*');

        $songWhere = '1 = 1';
        $albumWhere = '1 = 1';
        $params = [];
        if ($query !== '') {
            $params['q'] = '%' . addcslashes(mb_strtolower($query), '%_\\') . '%';
            $match = static fn(string ...$cols): string => '(' . implode(' OR ', array_map(
                static fn(string $c): string => "LOWER({$c}) LIKE :q ESCAPE '\\'",
                $cols,
            )) . ')';
            $songWhere = $match('m.title', 'm.artist', 'm.album', 'm.album_artist');
            $albumWhere = $match('m.album', 'm.album_artist', 'm.artist');
        }

        $songParams = $params;
        if (($since = $request->get('changedSince')) !== null && preg_match('/^\d+$/', $since) === 1) {
            $songWhere .= ' AND m.updated_at > :since';
            $songParams['since'] = date('Y-m-d H:i:s', intdiv((int) $since, 1000));
        }

        $songRows = $this->library->songs($user, $songWhere, $songParams, 'm.id', $this->count($request, 'songCount'), $this->offset($request, 'songOffset'));

        $albums = $this->library->albums($user, $albumWhere, $params, 'name COLLATE NOCASE', $this->count($request, 'albumCount'), $this->offset($request, 'albumOffset'));
        $starredAlbums = $this->library->starredAlbumKeys();

        $artists = $this->library->artists($user);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $artists = array_values(array_filter($artists, static fn(array $a): bool => str_contains(mb_strtolower($a['name']), $needle)));
        }
        $artists = \array_slice($artists, $this->offset($request, 'artistOffset'), $this->count($request, 'artistCount'));
        $starredArtists = $this->library->starredArtistKeys();

        return [$method === 'search2' ? 'searchResult2' : 'searchResult3' => [
            'artist' => array_map(static fn(array $a): array => AlbumMapper::artist($a, $starredArtists), $artists),
            'album' => array_map(static fn(array $a): array => AlbumMapper::album($a, $starredAlbums), $albums),
            'song' => $this->songs->children($user, $songRows),
        ]];
    }

    private function count(SubsonicRequest $request, string $key): int
    {
        return max(0, min(self::MAX_COUNT, $request->int($key, 20)));
    }

    private function offset(SubsonicRequest $request, string $key): int
    {
        return max(0, $request->int($key, 0));
    }
}
