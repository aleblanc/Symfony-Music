<?php

/*
 * Standalone test for MetadataWriter on a generated MP3: writes new fields
 * (track "N/total", disc, year, album artist, cover) and checks that a
 * partial update MERGES with existing tags instead of wiping them (getID3's
 * writer replaces the whole tag, which used to drop year/track/cover/rating
 * on every edit).
 *
 * Run:  php tests/metadata-writer-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\MetadataWriter;

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

// Silent MPEG-1 Layer III frames (128 kbps, 44.1 kHz): 417-byte frames.
$file = sys_get_temp_dir() . '/mw-test-' . bin2hex(random_bytes(4)) . '.mp3';
file_put_contents($file, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 200));
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

$writer = new MetadataWriter(__DIR__ . '/../lib/getid3');

try {
    // 1) Full enrichment write.
    $ok = $writer->writeTags($file, [
        'title' => 'Fitter Happier',
        'artist' => 'Radiohead',
        'album' => 'OK Computer',
        'albumArtist' => 'Radiohead',
        'genre' => 'Alternative Rock',
        'year' => 1997,
        'trackNumber' => 7,
        'trackTotal' => 12,
        'discNumber' => 1,
        'discTotal' => 1,
    ], $jpeg);
    check('full write succeeds', $ok);
    $info = readTags($file);
    $c = $info['comments'] ?? [];
    check('title', ($c['title'][0] ?? null) === 'Fitter Happier');
    // getID3 splits TRCK "7/12" into track_number + totaltracks on read.
    check('track N/total', ($info['tags']['id3v2']['track_number'][0] ?? null) === '7' && ($info['tags']['id3v2']['totaltracks'][0] ?? null) === '12', json_encode($info['tags']['id3v2']));
    check('disc N/total', ($info['tags']['id3v2']['part_of_a_set'][0] ?? null) === '1/1');
    check('album artist (TPE2)', ($info['tags']['id3v2']['band'][0] ?? null) === 'Radiohead');
    check('year', ($c['year'][0] ?? null) === '1997');
    check('cover embedded', ($c['picture'][0]['data'] ?? null) === $jpeg);

    // Simulate a WMP star rating already in the file (POPM).
    $info = readTags($file);
    $w = new getid3_writetags();
    $w->filename = $file;
    $w->tagformats = ['id3v2.3'];
    $w->tag_encoding = 'UTF-8';
    $w->tag_data = [
        'title' => ['Fitter Happier'], 'artist' => ['Radiohead'], 'album' => ['OK Computer'], 'band' => ['Radiohead'],
        'year' => ['1997'], 'track_number' => ['7/12'], 'part_of_a_set' => ['1/1'], 'genre' => ['Alternative Rock'],
        'attached_picture' => [['data' => $jpeg, 'picturetypeid' => 3, 'description' => 'Cover', 'mime' => 'image/jpeg']],
        'popularimeter' => ['email' => 'Windows Media Player 9 Series', 'rating' => 196, 'data' => 0],
    ];
    check('fixture rating written', $w->WriteTags(), implode('; ', $w->errors));

    // 2) The legacy edit form only sends title/artist/album/genre/lyrics.
    $ok = $writer->writeTags($file, ['title' => 'Fitter Happier (Remastered)', 'artist' => 'Radiohead', 'album' => 'OK Computer', 'genre' => 'Rock', 'lyrics' => 'Fitter, happier']);
    check('partial write succeeds', $ok);
    $info = readTags($file);
    $c = $info['comments'] ?? [];
    check('partial: title updated', ($c['title'][0] ?? null) === 'Fitter Happier (Remastered)');
    check('partial: genre updated', ($c['genre'][0] ?? null) === 'Rock');
    check('partial: lyrics written', ($c['unsynchronised_lyric'][0] ?? null) === 'Fitter, happier');
    check('partial: year preserved', ($c['year'][0] ?? null) === '1997');
    check('partial: track and total preserved', ($info['tags']['id3v2']['track_number'][0] ?? null) === '7' && ($info['tags']['id3v2']['totaltracks'][0] ?? null) === '12');
    check('partial: album artist preserved', ($info['tags']['id3v2']['band'][0] ?? null) === 'Radiohead');
    check('partial: cover preserved', ($c['picture'][0]['data'] ?? null) === $jpeg);
    check('partial: POPM rating preserved', ($info['id3v2']['POPM'][0]['rating'] ?? null) === 196, json_encode($info['id3v2']['POPM'][0]['rating'] ?? null));

    // 3) Explicit empty value clears a field; track without total.
    $ok = $writer->writeTags($file, ['lyrics' => '', 'trackNumber' => 3]);
    $info = readTags($file);
    check('empty string clears lyrics', $ok && !isset($info['comments']['unsynchronised_lyric']));
    check('track without total', ($info['tags']['id3v2']['track_number'][0] ?? null) === '3' && !isset($info['tags']['id3v2']['totaltracks']));
    check('title untouched by unrelated write', ($info['comments']['title'][0] ?? null) === 'Fitter Happier (Remastered)');

    check('missing file → false', $writer->writeTags($file . '.nope', ['title' => 'x']) === false);
} finally {
    @unlink($file);
}

echo $failures === 0 ? "\nAll writer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
