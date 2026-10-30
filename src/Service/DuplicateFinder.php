<?php

namespace App\Service;

use App\Enum\AudioQuality;
use Doctrine\DBAL\Connection;
use App\Exception\UserFacingException;

/**
 * Tracks the library holds twice: the same file name with another extension
 * (an old MP3 left next to its M4A replacement), or the same artist and title
 * within a few seconds of each other (a rip and a download, a best-of copy).
 * merge() keeps one of a group: hearts, plays, playlists... of the others
 * move to it, their files go to MUSIC_DIR/_duplicates (same relative path,
 * so nothing is lost) and their rows are removed.
 */
final class DuplicateFinder
{
    public const TRASH_DIR = '_duplicates';
    /** Same artist and title: durations this close (seconds) are the same recording. */
    private const DURATION_TOLERANCE = 3;

    /** Tables keyed (owner, song): a row of the dropped track moves unless the kept one already has it. */
    private const KEYED = [
        'favorites' => 'user_id',
        'listen_later' => 'user_id',
        'offline_songs' => 'user_id',
        'user_song_settings' => 'user_id',
        'rhythm_favorites' => 'user_id',
        'playlist_songs' => 'playlist_id',
        'mix_songs' => 'mix_id',
    ];
    /** Plain logs: every row moves. */
    private const LOGS = ['history', 'rhythm_scores'];

    public function __construct(
        private readonly Connection $db,
        private readonly string $musicDir,
    ) {}

    /**
     * @return list<array{kind: string, keep: int, tracks: list<array<string, mixed>>}> same file name first, then same album, then the rest
     */
    public function groups(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, file, title, artist, album, year, duration, bitrate, bitrate_mode, audio_format, lossless, sample_rate, bits_per_sample, spotify_id, cutoff_hz, effective_kbps FROM music');
        $byId = [];
        $parent = [];
        $find = static function (int $id) use (&$parent): int {
            while ($parent[$id] !== $id) {
                $id = $parent[$id] = $parent[$parent[$id]];
            }

            return $id;
        };
        $union = static function (int $a, int $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        $stems = [];
        $titles = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $byId[$id] = $row;
            $parent[$id] = $id;
            $stems[preg_replace('/\.[^.\/]+$/', '', (string) $row['file'])][] = $id;
            $artist = self::normalize($row['artist']);
            $title = self::normalize($row['title']);
            if ($artist !== '' && $title !== '') {
                $titles[$artist . '|' . $title][] = $id;
            }
        }
        foreach ($stems as $ids) {
            for ($i = 1; $i < \count($ids); ++$i) {
                $union($ids[0], $ids[$i]);
            }
        }
        foreach ($titles as $ids) {
            usort($ids, static fn (int $a, int $b): int => (int) $byId[$a]['duration'] <=> (int) $byId[$b]['duration']);
            for ($i = 1; $i < \count($ids); ++$i) {
                if (abs((int) $byId[$ids[$i]]['duration'] - (int) $byId[$ids[$i - 1]]['duration']) <= self::DURATION_TOLERANCE) {
                    $union($ids[$i - 1], $ids[$i]);
                }
            }
        }

        $members = [];
        foreach ($byId as $id => $row) {
            $members[$find($id)][] = $id;
        }
        $members = array_values(array_filter($members, static fn (array $ids): bool => \count($ids) > 1));
        $usage = $this->usage(array_merge(...$members ?: [[]]));

        $groups = [];
        foreach ($members as $ids) {
            $tracks = array_map(fn (int $id): array => $this->describe($byId[$id], $usage[$id] ?? []), $ids);
            usort($tracks, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
            $stemSet = array_unique(array_map(static fn (array $t): string => preg_replace('/\.[^.\/]+$/', '', $t['file']), $tracks));
            $albumSet = array_unique(array_map(static fn (array $t): string => self::normalize($t['album']), $tracks));
            $groups[] = [
                'kind' => \count($stemSet) === 1 ? 'same_file' : (\count($albumSet) === 1 ? 'same_album' : 'other_album'),
                'keep' => $tracks[0]['id'],
                'tracks' => $tracks,
            ];
        }
        $rank = ['same_file' => 0, 'same_album' => 1, 'other_album' => 2];
        usort($groups, static fn (array $a, array $b): int => $rank[$a['kind']] <=> $rank[$b['kind']]
            ?: strcasecmp((string) $a['tracks'][0]['artist'], (string) $b['tracks'][0]['artist'])
            ?: strcasecmp((string) $a['tracks'][0]['title'], (string) $b['tracks'][0]['title']));

        return $groups;
    }

    /**
     * Keeps $keep, removes $drop (each one must be in $keep's group).
     *
     * @param list<int> $drop
     *
     * @return list<string> where the dropped files went
     */
    public function merge(int $keep, array $drop): array
    {
        $group = null;
        foreach ($this->groups() as $g) {
            if (\in_array($keep, array_column($g['tracks'], 'id'), true)) {
                $group = array_column($g['tracks'], 'file', 'id');
                break;
            }
        }
        $drop = array_values(array_unique(array_diff($drop, [$keep])));
        if ($group === null || $drop === [] || array_diff($drop, array_keys($group)) !== []) {
            throw new UserFacingException('These tracks are not duplicates of each other (any more): reload the page.', 'error.duplicates.not_duplicates');
        }

        $moved = [];
        try {
            foreach ($drop as $id) {
                $file = (string) $group[$id];
                if ($file !== '' && $file !== $group[$keep] && is_file($file)) {
                    $moved[$file] = $this->trash($file);
                }
            }
            $this->db->transactional(function () use ($keep, $drop): void {
                foreach ($drop as $id) {
                    $this->moveReferences($id, $keep);
                }
            });
        } catch (\Throwable $e) {
            foreach ($moved as $from => $to) {
                @rename($to, $from);
            }
            throw $e;
        }

        return array_values($moved);
    }

    private function moveReferences(int $from, int $to): void
    {
        $p = ['from' => $from, 'to' => $to];
        // Plays of the same user add up.
        $this->db->executeStatement('UPDATE play_counts SET play_count = play_count + (SELECT d.play_count FROM play_counts d WHERE d.song_id = :from AND d.user_id = play_counts.user_id) WHERE song_id = :to AND user_id IN (SELECT user_id FROM play_counts WHERE song_id = :from)', $p);
        foreach (self::KEYED + ['play_counts' => 'user_id'] as $table => $key) {
            $this->db->executeStatement("DELETE FROM $table WHERE song_id = :from AND $key IN (SELECT $key FROM $table WHERE song_id = :to)", $p);
            $this->db->executeStatement("UPDATE $table SET song_id = :to WHERE song_id = :from", $p);
        }
        foreach (self::LOGS as $table) {
            $this->db->executeStatement("UPDATE $table SET song_id = :to WHERE song_id = :from", $p);
        }
        // What the kept row lacks and the dropped one knows.
        $this->db->executeStatement('UPDATE music SET rating = COALESCE(rating, (SELECT rating FROM music WHERE id = :from)), spotify_id = COALESCE(spotify_id, (SELECT spotify_id FROM music WHERE id = :from)), lyrics = COALESCE(lyrics, (SELECT lyrics FROM music WHERE id = :from)) WHERE id = :to', $p);
        // Per-file data (charts, tag suggestions) does not carry over; deleted explicitly in case foreign keys are off.
        $this->db->executeStatement('DELETE FROM rhythm_charts WHERE song_id = :from', ['from' => $from]);
        $this->db->executeStatement('DELETE FROM metadata_suggestion WHERE music_id = :from', ['from' => $from]);
        $this->db->executeStatement('DELETE FROM music WHERE id = :from', ['from' => $from]);
    }

    /** Moves a file to MUSIC_DIR/_duplicates, same relative path (numbered when taken). */
    private function trash(string $file): string
    {
        $root = rtrim($this->musicDir, '/');
        $relative = str_starts_with($file, $root . '/') ? substr($file, \strlen($root) + 1) : basename($file);
        $target = $root . '/' . self::TRASH_DIR . '/' . $relative;
        for ($i = 2; file_exists($target); ++$i) {
            $target = $root . '/' . self::TRASH_DIR . '/' . preg_replace('/(\.[^.\/]+)?$/', " ($i)$1", $relative, 1);
        }
        if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0o777, true)) {
            throw new \RuntimeException('Cannot create ' . \dirname($target));
        }
        if (!rename($file, $target)) {
            throw new \RuntimeException('Cannot move ' . $file . ' to ' . $target);
        }

        return $target;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{favorites: int, plays: int, playlists: int}>
     */
    private function usage(array $ids): array
    {
        $usage = [];
        if ($ids === []) {
            return $usage;
        }
        $queries = [
            'favorites' => 'SELECT song_id, COUNT(*) FROM favorites WHERE song_id IN (?) GROUP BY song_id',
            'plays' => 'SELECT song_id, SUM(play_count) FROM play_counts WHERE song_id IN (?) GROUP BY song_id',
            'playlists' => 'SELECT song_id, COUNT(*) FROM playlist_songs WHERE song_id IN (?) GROUP BY song_id',
        ];
        foreach ($queries as $name => $sql) {
            foreach ($this->db->fetchAllKeyValue($sql, [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) as $id => $n) {
                $usage[(int) $id][$name] = (int) $n;
            }
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $usage
     *
     * @return array<string, mixed>
     */
    private function describe(array $row, array $usage): array
    {
        $format = $row['audio_format'] !== null ? (string) $row['audio_format'] : null;
        $lossless = $row['lossless'] !== null ? (bool) $row['lossless'] : null;
        $bitrate = $row['bitrate'] !== null ? (int) $row['bitrate'] : null;
        $file = (string) $row['file'];
        $effective = $row['effective_kbps'] !== null ? (int) $row['effective_kbps'] : null;
        $cutoff = $row['cutoff_hz'] !== null ? (int) $row['cutoff_hz'] : null;
        $quality = AudioQuality::classify($format, $lossless, $bitrate, $effective);

        return [
            'id' => (int) $row['id'],
            'file' => $file,
            'relative' => str_starts_with($file, rtrim($this->musicDir, '/') . '/') ? substr($file, \strlen(rtrim($this->musicDir, '/')) + 1) : $file,
            'exists' => is_file($file),
            'title' => $row['title'],
            'artist' => $row['artist'],
            'album' => $row['album'],
            'year' => $row['year'],
            'duration' => (int) $row['duration'],
            'bitrate' => $bitrate,
            'bitrateMode' => $row['bitrate_mode'],
            // app:cron:spectrum: treble cutoff (Hz, 0 = not analysable) and real bitrate of an inflated file.
            'cutoffHz' => $cutoff,
            'effectiveKbps' => $effective,
            'format' => strtoupper($format ?? pathinfo($file, \PATHINFO_EXTENSION)),
            'quality' => $quality,
            'spotifyId' => $row['spotify_id'],
            'favorites' => $usage['favorites'] ?? 0,
            'plays' => $usage['plays'] ?? 0,
            'playlists' => $usage['playlists'] ?? 0,
            // The suggested keeper: the best real quality tier (lossless first; MP3 320 and AAC 256 are
            // the same tier, an inflated file is judged on its real bitrate), then the treble that
            // goes highest (app:cron:spectrum), then the higher bitrate; missing files last.
            'score' => (is_file($file) ? 1_000_000_000_000 : 0)
                + ($quality !== null ? array_search($quality, AudioQuality::cases(), true) + 1 : 0) * 10_000_000_000
                + max(0, $cutoff ?? 0) * 100_000
                + intdiv($bitrate ?? 0, 1000),
        ];
    }

    private static function normalize(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }
}
