<?php

/*
 * Standalone test for MetadataWriter on an M4A file (AtomicParsley): writes
 * every field + cover + MusicBrainz ids, checks a partial update keeps the
 * rest, and that '' removes a field. Needs `ffmpeg` (to build the sample)
 * and `AtomicParsley`; skipped (exit 0) when either is missing.
 *
 * Run:  php tests/metadata-writer-m4a-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BinaryLocator;
use App\Service\MetadataWriter;
use Symfony\Component\Process\Process;

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

/** @return array<string, mixed> */
function readTags(string $file): array
{
    clearstatcache(true, $file);
    $getID3 = new getID3();
    $getID3->setOption(['encoding' => 'UTF-8']);
    $info = $getID3->analyze($file);
    getid3_lib::CopyTagsToComments($info);

    return $info;
}

$writer = new MetadataWriter(__DIR__ . '/../lib/getid3');
$ffmpeg = (new BinaryLocator())->find('ffmpeg');
if ($ffmpeg === null || !$writer->canWrite('m4a')) {
    echo "SKIP  ffmpeg or AtomicParsley not installed\n";
    exit(0);
}

$dir = sys_get_temp_dir() . '/mw-m4a-' . bin2hex(random_bytes(4));
mkdir($dir);
// A name a shell would choke on: arguments go through Process, never a shell.
$file = $dir . '/x$(touch pwned) "Track 01".m4a';
$build = new Process([$ffmpeg, '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-c:a', 'aac', '-metadata', 'comment=keep me', $file]);
$build->run();
if (!$build->isSuccessful()) {
    echo 'FAIL  could not build the sample: ' . $build->getErrorOutput() . "\n";
    exit(1);
}
$duration = (float) readTags($file)['playtime_seconds'];
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

try {
    // 1) Full write.
    $ok = $writer->writeTags($file, [
        'title' => 'Brain Stew',
        'artist' => 'Green Day',
        'album' => 'Insomniac',
        'albumArtist' => 'Green Day',
        'genre' => 'Punk Rock',
        'year' => 1995,
        'trackNumber' => 6,
        'trackTotal' => 14,
        'discNumber' => 1,
        'discTotal' => 2,
        'musicbrainzRecordingId' => '11111111-1111-1111-1111-111111111111',
        'musicbrainzReleaseId' => '22222222-2222-2222-2222-222222222222',
    ], $jpeg);
    check('full write succeeds', $ok, (string) $writer->lastError());
    $info = readTags($file);
    $c = $info['comments'] ?? [];
    check('title', ($c['title'][0] ?? null) === 'Brain Stew');
    check('artist', ($c['artist'][0] ?? null) === 'Green Day');
    check('album', ($c['album'][0] ?? null) === 'Insomniac');
    check('album artist', ($c['album_artist'][0] ?? null) === 'Green Day', json_encode($c['album_artist'] ?? null));
    check('genre', ($c['genre'][0] ?? null) === 'Punk Rock', json_encode($c['genre'] ?? null));
    check('year', str_starts_with((string) ($c['creation_date'][0] ?? $c['year'][0] ?? ''), '1995'), json_encode($c['creation_date'] ?? null));
    check('track 6/14', ($c['track_number'][0] ?? null) === '6/14', json_encode($c['track_number'] ?? null));
    check('disc 1/2', ($c['disc_number'][0] ?? null) === '1/2', json_encode($c['disc_number'] ?? null));
    check('cover embedded', ($c['picture'][0]['data'] ?? null) === $jpeg);
    check('other tags kept (comment)', ($c['comment'][0] ?? null) === 'keep me', json_encode($c['comment'] ?? null));
    $raw = (string) file_get_contents($file);
    check('MusicBrainz Track Id atom', str_contains($raw, 'MusicBrainz Track Id') && str_contains($raw, '11111111-1111-1111-1111-111111111111'));
    check('MusicBrainz Album Id atom', str_contains($raw, 'MusicBrainz Album Id') && str_contains($raw, '22222222-2222-2222-2222-222222222222'));
    check('audio intact', abs((float) $info['playtime_seconds'] - $duration) < 0.1);
    check('no temp files left', count(glob($dir . '/.tagw-*') ?: []) === 0);

    // 2) Partial update: only the title changes; '' removes the genre.
    $ok = $writer->writeTags($file, ['title' => 'Brain Stew (Live)', 'genre' => '']);
    check('partial write succeeds', $ok, (string) $writer->lastError());
    $c = readTags($file)['comments'] ?? [];
    check('title updated', ($c['title'][0] ?? null) === 'Brain Stew (Live)');
    check('genre removed', !isset($c['genre']), json_encode($c['genre'] ?? null));
    check('artist kept', ($c['artist'][0] ?? null) === 'Green Day');
    check('track kept', ($c['track_number'][0] ?? null) === '6/14');
    check('cover kept', ($c['picture'][0]['data'] ?? null) === $jpeg);

    // 3) '' removes the cover.
    $ok = $writer->writeTags($file, [], '');
    $c = readTags($file)['comments'] ?? [];
    check('cover removed', $ok && !isset($c['picture']));

    // 4) No track number: the atom is removed.
    $ok = $writer->writeTags($file, ['trackNumber' => null]);
    $c = readTags($file)['comments'] ?? [];
    check('track number removed', $ok && !isset($c['track_number']), json_encode($c['track_number'] ?? null));
    check('shell-looking name never executed', !file_exists($dir . '/pwned') && !file_exists(getcwd() . '/pwned'));
} finally {
    array_map('unlink', array_filter(glob($dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
    @rmdir($dir);
}

echo $failures === 0 ? "\nAll M4A writer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
