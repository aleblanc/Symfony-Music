<?php

namespace App\Subsonic;

/**
 * Subsonic ids for a library that has no album/artist tables: albums and
 * artists are identified by a short hash of their (normalised) tag values,
 * stored on each Music row (album_key / artist_key) so lookups stay indexed.
 *
 * Id forms: "<int>" song, "al-<key>" album, "ar-<key>" artist,
 * "pl-<int>" playlist, "pl-offline" the shared offline list.
 */
final class IdCodec
{
    public const OFFLINE_PLAYLIST = 'pl-offline';

    public static function artistKey(?string $albumArtist, ?string $artist): string
    {
        $name = self::norm($albumArtist) !== '' ? $albumArtist : $artist;

        return substr(sha1(self::norm($name)), 0, 16);
    }

    public static function albumKey(?string $albumArtist, ?string $artist, ?string $album): string
    {
        return substr(sha1(self::artistKey($albumArtist, $artist) . "\0" . self::norm($album)), 0, 16);
    }

    public static function album(string $key): string
    {
        return 'al-' . $key;
    }

    public static function artist(string $key): string
    {
        return 'ar-' . $key;
    }

    public static function playlist(int $id): string
    {
        return 'pl-' . $id;
    }

    /**
     * @return array{type: 'song'|'album'|'artist'|'playlist'|'offline', value: int|string}
     *
     * @throws SubsonicException code 70 on any unrecognised id
     */
    public static function decode(string $id): array
    {
        if ($id === self::OFFLINE_PLAYLIST) {
            return ['type' => 'offline', 'value' => 'offline'];
        }
        if (preg_match('/^\d+$/', $id) === 1) {
            return ['type' => 'song', 'value' => (int) $id];
        }
        if (preg_match('/^pl-(\d+)$/', $id, $m) === 1) {
            return ['type' => 'playlist', 'value' => (int) $m[1]];
        }
        if (preg_match('/^(al|ar)-([0-9a-f]{16})$/', $id, $m) === 1) {
            return ['type' => $m[1] === 'al' ? 'album' : 'artist', 'value' => $m[2]];
        }

        throw SubsonicException::notFound("Id '{$id}'");
    }

    private static function norm(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }
}
