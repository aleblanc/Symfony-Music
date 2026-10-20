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

    // 4) Frames the writer doesn't manage must survive: Spotify URL (WOAF, written by
    //    app:spotify:match), custom TXXX, MusicBrainz UFID, a second (back) picture.
    $w = new getid3_writetags();
    $w->filename = $file;
    $w->tagformats = ['id3v2.3'];
    $w->tag_encoding = 'UTF-8';
    $w->tag_data = [
        'title' => ['Before'], 'artist' => ['Radiohead'], 'year' => ['1997'],
        'url_file' => ['https://open.spotify.com/track/0123456789abcdefghijkl'],
        'text' => [['description' => 'MusicBrainz Album Id', 'data' => 'b1392450-e666-3926-a536-22c65f834433'], ['description' => 'MOOD', 'data' => 'calm']],
        'unique_file_identifier' => ['ownerid' => 'http://musicbrainz.org', 'data' => '9c3d7f2e-0000-4000-8000-000000000001'],
        'attached_picture' => [
            ['data' => $jpeg, 'picturetypeid' => 3, 'description' => 'front', 'mime' => 'image/jpeg'],
            ['data' => $jpeg . 'back', 'picturetypeid' => 4, 'description' => 'back', 'mime' => 'image/jpeg'],
        ],
    ];
    check('fixture with foreign frames written', $w->WriteTags(), implode('; ', $w->errors));
    $ok = $writer->writeTags($file, ['title' => 'After', 'trackNumber' => 5]);
    $info = readTags($file);
    check('foreign frames: write succeeds', $ok, (string) $writer->lastError());
    check('foreign frames: title updated', ($info['comments']['title'][0] ?? null) === 'After');
    check('foreign frames: Spotify URL (WOAF) kept', ($info['comments']['url_file'][0] ?? null) === 'https://open.spotify.com/track/0123456789abcdefghijkl', json_encode($info['comments']['url_file'] ?? null));
    check('foreign frames: TXXX kept', count($info['id3v2']['TXXX'] ?? []) === 2);
    check('foreign frames: UFID kept', ($info['id3v2']['UFID'][0]['data'] ?? null) === '9c3d7f2e-0000-4000-8000-000000000001');
    check('foreign frames: both pictures kept', count($info['id3v2']['APIC'] ?? []) === 2);
    // A new front cover replaces the front one only.
    $writer->writeTags($file, [], $jpeg . 'new');
    $info = readTags($file);
    $types = array_map(static fn(array $a): string => $a['picturetypeid'] . ':' . (str_ends_with($a['data'], 'new') ? 'new' : (str_ends_with($a['data'], 'back') ? 'back' : 'old')), $info['id3v2']['APIC'] ?? []);
    sort($types);
    check('new front cover replaces only the front picture', $types === ['3:new', '4:back'], json_encode($types));

    // 5) MusicBrainz ids, Picard-style (UFID + TXXX).
    $writer->writeTags($file, ['musicbrainzRecordingId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'musicbrainzReleaseId' => 'bbbbbbbb-0000-4000-8000-000000000002', 'musicbrainzReleaseGroupId' => 'cccccccc-0000-4000-8000-000000000003']);
    $info = readTags($file);
    $txxx = [];
    foreach ($info['id3v2']['TXXX'] ?? [] as $t) {
        $txxx[$t['description']] = $t['data'];
    }
    check('MBID: recording id in UFID (http://musicbrainz.org)', ($info['id3v2']['UFID'][0]['data'] ?? null) === 'aaaaaaaa-0000-4000-8000-000000000001');
    check('MBID: release + release group in TXXX', ($txxx['MusicBrainz Album Id'] ?? null) === 'bbbbbbbb-0000-4000-8000-000000000002' && ($txxx['MusicBrainz Release Group Id'] ?? null) === 'cccccccc-0000-4000-8000-000000000003', json_encode($txxx));
    check('MBID: other TXXX untouched', ($txxx['MOOD'] ?? null) === 'calm');

    check('missing file → false', $writer->writeTags($file . '.nope', ['title' => 'x']) === false);
} finally {
    @unlink($file);
}

echo $failures === 0 ? "\nAll writer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
