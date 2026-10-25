<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Subsonic\AlbumMapper;
use App\Subsonic\IdCodec;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;

/**
 * Library browsing, tag-based (getArtists/getArtist/getAlbum, "ID3") and
 * folder-style (getIndexes/getMusicDirectory, used by Tempo and older
 * clients) — both built on the same album_key / artist_key aggregates.
 */
final class BrowsingHandler implements SubsonicHandler
{
    public function __construct(
        private readonly LibraryQuery $library,
        private readonly SongMapper $songs,
        private readonly ArtistProfileRepository $profiles,
    ) {}

    public static function methods(): array
    {
        return ['getArtists', 'getIndexes', 'getArtist', 'getAlbum', 'getSong', 'getGenres', 'getMusicDirectory',
            'getArtistInfo', 'getArtistInfo2', 'getAlbumInfo', 'getAlbumInfo2'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        return match ($method) {
            'getArtists' => ['artists' => ['ignoredArticles' => AlbumMapper::IGNORED_ARTICLES, 'index' => AlbumMapper::index($this->allArtists($user))]],
            'getIndexes' => ['indexes' => [
                'ignoredArticles' => AlbumMapper::IGNORED_ARTICLES,
                'lastModified' => (int) (microtime(true) * 1000),
                'index' => AlbumMapper::index($this->allArtists($user)),
            ]],
            'getArtist' => ['artist' => $this->artist($user, $this->key($request, 'artist'))],
            'getAlbum' => ['album' => $this->album($user, $this->key($request, 'album'))],
            'getSong' => ['song' => $this->song($user, $request->require('id'))],
            'getGenres' => ['genres' => ['genre' => $this->library->genres($user)]],
            'getMusicDirectory' => ['directory' => $this->directory($user, $request->require('id'))],
            'getArtistInfo', 'getArtistInfo2' => [$method === 'getArtistInfo' ? 'artistInfo' : 'artistInfo2' => $this->artistInfo($user, $this->key($request, 'artist'))],
            'getAlbumInfo', 'getAlbumInfo2' => ['albumInfo' => $this->albumInfo($user, $this->key($request, 'album'))],
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }

    /** @return list<array<string, mixed>> */
    private function allArtists(User $user): array
    {
        $starred = $this->library->starredArtistKeys();
        $profiles = $this->profiles->allByName();

        return array_map(
            static fn(array $row): array => AlbumMapper::artist($row, $starred, isset($profiles[$row['name']]) ? $profiles[$row['name']]->getImageUrl() : null),
            $this->library->artists($user),
        );
    }

    /** @return array<string, mixed> */
    private function artist(User $user, string $key): array
    {
        $row = $this->library->artist($user, $key) ?? throw SubsonicException::notFound('Artist');
        $starredAlbums = $this->library->starredAlbumKeys();
        $albums = $this->library->albums($user, 'm.artist_key = :key', ['key' => $key], 'year IS NULL, year, name COLLATE NOCASE');

        return AlbumMapper::artist($row, $this->library->starredArtistKeys(), $this->profiles->findOneByName($row['name'])?->getImageUrl())
            + ['album' => array_map(static fn(array $a): array => AlbumMapper::album($a, $starredAlbums), $albums)];
    }

    /** @return array<string, mixed> */
    private function album(User $user, string $key): array
    {
        $row = $this->library->album($user, $key) ?? throw SubsonicException::notFound('Album');
        $songs = $this->library->songs($user, 'm.album_key = :key', ['key' => $key], 'COALESCE(m.disc_number, 1), m.track_number, m.title COLLATE NOCASE', 1000);

        return AlbumMapper::album($row, $this->library->starredAlbumKeys()) + ['song' => $this->songs->children($user, $songs)];
    }

    /** @return array<string, mixed> */
    private function song(User $user, string $id): array
    {
        $decoded = IdCodec::decode($id);
        if ($decoded['type'] !== 'song') {
            throw SubsonicException::notFound('Song');
        }
        $rows = $this->library->songsByIds($user, [(int) $decoded['value']]);

        return $this->songs->children($user, $rows)[0] ?? throw SubsonicException::notFound('Song');
    }

    /**
     * Folder view: an artist "directory" lists its albums, an album lists its songs.
     *
     * @return array<string, mixed>
     */
    private function directory(User $user, string $id): array
    {
        $decoded = IdCodec::decode($id);
        if ($decoded['type'] === 'artist') {
            $artist = $this->artist($user, (string) $decoded['value']);

            return ['id' => $id, 'name' => $artist['name'], 'child' => array_map(
                static fn(array $album): array => $album + ['title' => $album['name']],
                $artist['album'],
            )];
        }
        if ($decoded['type'] === 'album') {
            $album = $this->album($user, (string) $decoded['value']);

            return ['id' => $id, 'name' => $album['name'], 'parent' => $album['artistId'], 'child' => $album['song']];
        }
        if ((string) $decoded['value'] === (string) SystemHandler::MUSIC_FOLDER_ID) {
            // Some clients open the music folder itself as a directory.
            return ['id' => $id, 'name' => 'Bibliothèque', 'child' => array_map(
                static fn(array $a): array => ['id' => $a['id'], 'title' => $a['name'], 'artist' => $a['name'], 'isDir' => true, 'coverArt' => $a['coverArt']],
                $this->allArtists($user),
            )];
        }

        throw SubsonicException::notFound('Directory');
    }

    /** @return array<string, mixed> */
    private function artistInfo(User $user, string $key): array
    {
        $row = $this->library->artist($user, $key) ?? throw SubsonicException::notFound('Artist');
        $profile = $this->profiles->findOneByName($row['name']);
        $image = $profile?->getImageUrl();

        return [
            'biography' => $profile?->getSummary(),
            'musicBrainzId' => $profile?->getMbid(),
            'lastFmUrl' => $profile?->getWikipediaUrl(),
            'smallImageUrl' => $image,
            'mediumImageUrl' => $image,
            'largeImageUrl' => $image,
        ];
    }

    /** @return array<string, mixed> */
    private function albumInfo(User $user, string $key): array
    {
        if ($this->library->album($user, $key) === null) {
            throw SubsonicException::notFound('Album');
        }

        return ['notes' => ''];
    }

    /** Accepts the "id" param of the expected kind (al-/ar-) and returns its key. */
    private function key(SubsonicRequest $request, string $type): string
    {
        $decoded = IdCodec::decode($request->require('id'));
        if ($decoded['type'] !== $type) {
            throw SubsonicException::notFound(ucfirst($type));
        }

        return (string) $decoded['value'];
    }
}
