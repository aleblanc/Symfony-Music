<?php

/*
 * Standalone test for SongMapper::child(): a `music` row (as LibraryQuery
 * returns it, no image BLOB) mapped to a Subsonic "child". No database.
 *
 * Run:  php tests/subsonic-songmapper-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Subsonic\SongMapper;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}\n";
    } else {
        ++$failures;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$tmp = sys_get_temp_dir() . '/subsonic-mapper-' . getmypid();
@mkdir("{$tmp}/Daft Punk/Discovery (2001)", 0777, true);
$file = "{$tmp}/Daft Punk/Discovery (2001)/01 One More Time.m4a";
file_put_contents($file, str_repeat('x', 1234));

$full = [
    'id' => 42, 'file' => $file, 'title' => 'One More Time', 'artist' => 'Daft Punk', 'album' => 'Discovery',
    'album_artist' => 'Daft Punk', 'genre' => 'House', 'year' => 2001, 'track_number' => 1, 'disc_number' => 1,
    'duration' => 320, 'bitrate' => 256000, 'sample_rate' => 44100, 'bits_per_sample' => 16, 'channels' => 2,
    'rating' => 4, 'album_key' => '0123456789abcdef', 'artist_key' => 'fedcba9876543210',
    'added_at' => '2024-05-01 10:00:00', 'updated_at' => '2024-05-02 10:00:00', 'bpm' => 122.6,
    'replay_gain_track' => -7.5, 'replay_gain_track_peak' => 0.98, 'replay_gain_album' => -6.9, 'replay_gain_album_peak' => null,
    'has_image' => 1,
];
$ctx = ['starred' => [42 => true], 'plays' => [42 => ['count' => 7, 'last' => '2026-10-01 20:00:00']]];
$c = SongMapper::child($full, $ctx, $tmp);

check('id is a string', $c['id'] === '42');
check('album/parent/cover ids', $c['albumId'] === 'al-0123456789abcdef' && $c['parent'] === 'al-0123456789abcdef' && $c['coverArt'] === 'al-0123456789abcdef');
check('artist id', $c['artistId'] === 'ar-fedcba9876543210');
check('bitRate in kbps', $c['bitRate'] === 256);
check('size from disk', $c['size'] === 1234);
check('m4a suffix + mime', $c['suffix'] === 'm4a' && $c['contentType'] === 'audio/mp4');
check('path relative to library root', $c['path'] === 'Daft Punk/Discovery (2001)/01 One More Time.m4a', $c['path']);
check('rating → userRating', $c['userRating'] === 4);
check('play count + played', $c['playCount'] === 7 && str_starts_with((string) $c['played'], '2026-10-01T20:00:00'));
check('starred is set', $c['starred'] !== null && str_starts_with($c['starred'], '2024-05-01T10:00:00'));
check('created is ISO 8601', str_starts_with((string) $c['created'], '2024-05-01T10:00:00'));
check('replayGain without null peak', $c['replayGain'] === ['trackGain' => -7.5, 'trackPeak' => 0.98, 'albumGain' => -6.9]);
check('bpm rounded', $c['bpm'] === 123);
check('audio details', $c['samplingRate'] === 44100 && $c['bitDepth'] === 16 && $c['channelCount'] === 2);
check('song flags', $c['isDir'] === false && $c['type'] === 'music' && $c['mediaType'] === 'song');

foreach (['mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'xyz' => 'application/octet-stream'] as $ext => $mime) {
    check("mime for {$ext}", SongMapper::contentType($ext) === $mime);
}

$sparse = [
    'id' => 7, 'file' => '/elsewhere/A/B/c.mp3', 'title' => '  ', 'artist' => null, 'album' => null, 'album_artist' => '',
    'genre' => ' ', 'year' => null, 'track_number' => null, 'disc_number' => null, 'duration' => null, 'bitrate' => null,
    'sample_rate' => null, 'bits_per_sample' => null, 'channels' => null, 'rating' => null, 'album_key' => null,
    'artist_key' => null, 'added_at' => null, 'updated_at' => null, 'bpm' => null, 'replay_gain_track' => null,
    'replay_gain_track_peak' => null, 'replay_gain_album' => null, 'replay_gain_album_peak' => null, 'has_image' => 0,
];
$s = SongMapper::child($sparse, ['starred' => [], 'plays' => []], $tmp);
check('sparse: title falls back to filename', $s['title'] === 'c');
check('sparse: missing file → size 0', $s['size'] === 0);
check('sparse: path outside root keeps last 3 segments', $s['path'] === 'A/B/c.mp3', $s['path']);
check('sparse: no rating/starred/replayGain/genre', $s['userRating'] === null && $s['starred'] === null && $s['replayGain'] === null && $s['genre'] === null);
check('sparse: cover falls back to song id', $s['coverArt'] === '7' && $s['albumId'] === null);
check('sparse: duration 0, playCount 0', $s['duration'] === 0 && $s['playCount'] === 0);

unlink($file);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
