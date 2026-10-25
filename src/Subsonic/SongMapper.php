<?php

namespace App\Subsonic;

use App\Entity\User;

/**
 * Turns `music` rows (from LibraryQuery, without the image BLOB) into
 * Subsonic "child" entries. The mapping itself is the pure static child(),
 * so it can be tested without a database.
 */
final class SongMapper
{
    private const MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'flac' => 'audio/flac',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'mp4' => 'audio/mp4',
    ];

    public function __construct(
        private readonly LibraryQuery $library,
        private readonly string $musicDir,
    ) {}

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function children(User $user, array $rows): array
    {
        $ctx = $this->library->songContext($user, array_map(static fn(array $r): int => (int) $r['id'], $rows));

        return array_map(fn(array $row): array => self::child($row, $ctx, $this->musicDir), $rows);
    }

    public static function contentType(string $suffix): string
    {
        return self::MIME_TYPES[strtolower($suffix)] ?? 'application/octet-stream';
    }

    /**
     * @param array<string, mixed>                                                                 $row
     * @param array{starred: array<int, true>, plays: array<int, array{count: int, last: ?string}>} $ctx
     *
     * @return array<string, mixed>
     */
    public static function child(array $row, array $ctx, string $musicDir): array
    {
        $id = (int) $row['id'];
        $file = (string) $row['file'];
        $suffix = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        $albumId = $row['album_key'] !== null ? IdCodec::album((string) $row['album_key']) : null;
        $title = trim((string) $row['title']) !== '' ? (string) $row['title'] : pathinfo($file, \PATHINFO_FILENAME);
        $albumArtist = trim((string) $row['album_artist']) !== '' ? (string) $row['album_artist'] : null;
        $created = self::iso($row['added_at'] ?? null);
        $plays = $ctx['plays'][$id] ?? null;
        $rating = (int) ($row['rating'] ?? 0);
        $replayGain = array_filter([
            'trackGain' => self::float($row['replay_gain_track'] ?? null),
            'trackPeak' => self::float($row['replay_gain_track_peak'] ?? null),
            'albumGain' => self::float($row['replay_gain_album'] ?? null),
            'albumPeak' => self::float($row['replay_gain_album_peak'] ?? null),
        ], static fn(?float $v): bool => $v !== null);

        return [
            'id' => (string) $id,
            'parent' => $albumId,
            'isDir' => false,
            'title' => $title,
            'album' => $row['album'] !== null ? (string) $row['album'] : null,
            'artist' => $row['artist'] !== null ? (string) $row['artist'] : null,
            'albumArtist' => $albumArtist,
            'displayAlbumArtist' => $albumArtist,
            'track' => self::int($row['track_number'] ?? null),
            'discNumber' => self::int($row['disc_number'] ?? null),
            'year' => self::int($row['year'] ?? null),
            'genre' => $row['genre'] !== null && trim((string) $row['genre']) !== '' ? (string) $row['genre'] : null,
            'coverArt' => $albumId ?? (string) $id,
            'size' => is_file($file) ? (int) filesize($file) : 0,
            'contentType' => self::contentType($suffix),
            'suffix' => $suffix,
            'duration' => self::int($row['duration'] ?? null) ?? 0,
            'bitRate' => $row['bitrate'] !== null ? (int) round((int) $row['bitrate'] / 1000) : null,
            'samplingRate' => self::int($row['sample_rate'] ?? null),
            'bitDepth' => self::int($row['bits_per_sample'] ?? null),
            'channelCount' => self::int($row['channels'] ?? null),
            'path' => self::relativePath($file, $musicDir),
            'albumId' => $albumId,
            'artistId' => $row['artist_key'] !== null ? IdCodec::artist((string) $row['artist_key']) : null,
            'type' => 'music',
            'mediaType' => 'song',
            'isVideo' => false,
            'created' => $created,
            // Favorites carry no timestamp; the add date stands in for it.
            'starred' => isset($ctx['starred'][$id]) ? ($created ?? self::iso('1970-01-01 00:00:00')) : null,
            'userRating' => $rating > 0 ? $rating : null,
            'playCount' => $plays['count'] ?? 0,
            'played' => self::iso($plays['last'] ?? null),
            'bpm' => $row['bpm'] !== null ? (int) round((float) $row['bpm']) : null,
            'replayGain' => $replayGain !== [] ? $replayGain : null,
        ];
    }

    /**
     * Path relative to the library root. Tracks stored outside it keep their
     * last three segments (Artist/Album/file), which is what clients use to
     * build a folder view.
     */
    public static function relativePath(string $file, string $musicDir): string
    {
        $root = rtrim($musicDir, '/') . '/';
        if ($musicDir !== '' && str_starts_with($file, $root)) {
            return substr($file, \strlen($root));
        }

        return implode('/', \array_slice(explode('/', trim($file, '/')), -3));
    }

    /** SQLite "Y-m-d H:i:s" (local time) → ISO 8601. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $value)->format('Y-m-d\TH:i:s.000P');
        } catch (\Exception) {
            return null;
        }
    }

    private static function int(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
