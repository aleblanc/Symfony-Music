<?php

namespace App\Subsonic;

/**
 * Album and artist aggregates (from LibraryQuery) → Subsonic AlbumID3 /
 * ArtistID3 entries, plus the folder-style forms used by getIndexes and
 * getMusicDirectory.
 */
final class AlbumMapper
{
    /** Articles skipped when indexing artists by first letter. */
    public const IGNORED_ARTICLES = 'The Les Le La L\'';

    /**
     * @param array{album_key: string, name: string, artist: string, artist_key: string, song_count: int, duration: int, year: ?int, genre: ?string, created: ?string, rating: ?float, cover_song: int} $row
     * @param array<string, string>                                                                                                                                                                  $starred album_key => date
     * @param array<string, int>                                                                                                                                                                     $playCounts album_key => plays
     *
     * @return array<string, mixed>
     */
    public static function album(array $row, array $starred = [], array $playCounts = []): array
    {
        $id = IdCodec::album($row['album_key']);

        return [
            'id' => $id,
            'name' => $row['name'] !== '' ? $row['name'] : '(sans album)',
            'title' => $row['name'],
            'album' => $row['name'],
            'artist' => $row['artist'],
            'artistId' => IdCodec::artist($row['artist_key']),
            'displayArtist' => $row['artist'],
            'coverArt' => $id,
            'songCount' => $row['song_count'],
            'duration' => $row['duration'],
            'playCount' => $playCounts[$row['album_key']] ?? 0,
            'created' => SongMapper::iso($row['created']),
            'starred' => SongMapper::iso($starred[$row['album_key']] ?? null),
            'year' => $row['year'],
            'genre' => $row['genre'],
            'userRating' => $row['rating'] !== null ? (int) round($row['rating']) : null,
            'isCompilation' => mb_strtolower($row['artist']) === 'various artists',
            'isDir' => true,
            'parent' => IdCodec::artist($row['artist_key']),
            'mediaType' => 'album',
        ];
    }

    /**
     * @param array{artist_key: string, name: string, album_count: int, cover_album: string} $row
     * @param array<string, string>                                                       $starred artist_key => date
     *
     * @return array<string, mixed>
     */
    public static function artist(array $row, array $starred = [], ?string $imageUrl = null): array
    {
        return [
            'id' => IdCodec::artist($row['artist_key']),
            'name' => $row['name'] !== '' ? $row['name'] : '(inconnu)',
            'coverArt' => IdCodec::artist($row['artist_key']),
            'albumCount' => $row['album_count'],
            'artistImageUrl' => $imageUrl,
            'starred' => SongMapper::iso($starred[$row['artist_key']] ?? null),
        ];
    }

    /**
     * Groups artists by index letter (articles ignored, non-letters under "#").
     *
     * @param list<array<string, mixed>> $artists mapped artist entries
     *
     * @return list<array{name: string, artist: list<array<string, mixed>>}>
     */
    public static function index(array $artists): array
    {
        $groups = [];
        foreach ($artists as $artist) {
            $letter = self::indexLetter((string) $artist['name']);
            $groups[$letter][] = $artist;
        }
        uksort($groups, static fn(string $a, string $b): int => ($a === '#') <=> ($b === '#') ?: strcmp($a, $b));

        $index = [];
        foreach ($groups as $name => $list) {
            usort($list, static fn(array $a, array $b): int => strcasecmp(self::sortName((string) $a['name']), self::sortName((string) $b['name'])));
            $index[] = ['name' => (string) $name, 'artist' => $list];
        }

        return $index;
    }

    public static function sortName(string $name): string
    {
        foreach (explode(' ', self::IGNORED_ARTICLES) as $article) {
            $prefix = str_ends_with($article, "'") ? $article : $article . ' ';
            if (mb_stripos($name, $prefix) === 0 && mb_strlen($name) > mb_strlen($prefix)) {
                return mb_substr($name, mb_strlen($prefix));
            }
        }

        return $name;
    }

    private static function indexLetter(string $name): string
    {
        $first = mb_strtoupper(mb_substr(self::sortName($name), 0, 1));
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $first);
        $letter = $ascii !== '' ? strtoupper($ascii[0]) : '';

        return preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : '#';
    }
}
