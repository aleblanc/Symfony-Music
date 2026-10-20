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

    // 6) Real-world frames from the import run (34 files refused before): PRIV (Windows
    //    Media Player / Amazon), ID3v2.4 TDRC, two COMM in different languages, WXXX,
    //    GEOB, TCOP, TOPE, TCON — all kept, version kept.
    require_once __DIR__ . '/../lib/getid3/write.id3v2.php';
    $v24 = sys_get_temp_dir() . '/mw-v24-' . bin2hex(random_bytes(4)) . '.mp3';
    file_put_contents($v24, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 200));
    $raw = new getid3_write_id3v2();
    $raw->filename = $v24;
    $raw->majorversion = 4;
    $raw->tag_data = [
        'TIT2' => [['encodingid' => 3, 'data' => 'Résidents']],
        'TPE1' => [['encodingid' => 3, 'data' => 'Alain Bashung']],
        'TDRC' => [['encodingid' => 3, 'data' => '2008-03-24']],
        'TCON' => [['encodingid' => 3, 'data' => 'Chanson']],
        'TCOP' => [['encodingid' => 3, 'data' => '2008 Barclay']],
        'TOPE' => [['encodingid' => 3, 'data' => 'Original Artist']],
        'PRIV' => [['ownerid' => 'WM/MediaClassPrimaryID', 'data' => "\x01\x02\x03"], ['ownerid' => 'Amazon.com', 'data' => 'abc']],
        'COMM' => [['encodingid' => 3, 'language' => 'eng', 'description' => '', 'data' => 'hello'], ['encodingid' => 3, 'language' => 'fra', 'description' => 'note', 'data' => 'salut']],
        'WXXX' => [['encodingid' => 3, 'description' => 'Amazon', 'data' => 'http://a.example']],
        'GEOB' => [['encodingid' => 3, 'mime' => 'application/x', 'filename' => 'f.bin', 'description' => 'd', 'data' => 'OBJ']],
    ];
    check('v2.4 fixture written', $raw->WriteID3v2(), implode('; ', $raw->errors));
    try {
        $ok = $writer->writeTags($v24, ['title' => 'Résidents de la République', 'year' => 2008, 'trackNumber' => 2, 'trackTotal' => 12, 'musicbrainzRecordingId' => 'rid'], $jpeg);
        $info = readTags($v24);
        check('real-world frames: write succeeds', $ok, (string) $writer->lastError());
        check('real-world frames: tag version kept (2.4)', ($info['id3v2']['majorversion'] ?? 0) === 4);
        check('real-world frames: title updated (UTF-8)', ($info['comments']['title'][0] ?? '') === 'Résidents de la République');
        $counts = array_map(static fn(string $f): int => count($info['id3v2'][$f] ?? []), ['PRIV' => 'PRIV', 'COMM' => 'COMM', 'WXXX' => 'WXXX', 'GEOB' => 'GEOB', 'TCOP' => 'TCOP', 'TOPE' => 'TOPE', 'TCON' => 'TCON', 'TPE1' => 'TPE1']);
        check('real-world frames: PRIV×2, COMM×2, WXXX, GEOB, TCOP, TOPE, TCON, TPE1 kept', $counts === ['PRIV' => 2, 'COMM' => 2, 'WXXX' => 1, 'GEOB' => 1, 'TCOP' => 1, 'TOPE' => 1, 'TCON' => 1, 'TPE1' => 1], json_encode($counts));
        check('real-world frames: year in TDRC (v2.4)', ($info['id3v2']['TDRC'][0]['data'] ?? '') === '2008', json_encode($info['id3v2']['TDRC'][0]['data'] ?? null));
        check('real-world frames: track, cover, MBID added', ($info['tags']['id3v2']['track_number'][0] ?? '') === '2' && isset($info['id3v2']['APIC'][0]) && ($info['id3v2']['UFID'][0]['data'] ?? '') === 'rid');
    } finally {
        @unlink($v24);
    }

    // 7) Malformed frames found in downloaded files (getID3 refuses to write them as
    //    they are): APIC with mime "JPG", GEOB without mime, WXXX without URL.
    //    Built by hand since getID3 cannot produce them.
    $frame = static fn(string $id, string $data): string => $id . pack('N', strlen($data)) . "\x00\x00" . $data;
    $frames = $frame('TIT2', "\x00Old title")
        . $frame('APIC', "\x00JPG\x00\x03\x00" . $jpeg)
        . $frame('GEOB', "\x00\x00f.bin\x00desc\x00OBJECT")
        . $frame('WXXX', "\x00Site\x00")
        . $frame('COMM', "\x00eng\x00first comment")
        . $frame('COMM', "\x00eng\x00duplicate comment")
        . $frame('RVA2', "track\x00\x01\x00\x10\x00")
        . $frame('COMM', "\x00\\\\\\desc\x00comment with a garbage language code");
    $size = strlen($frames);
    $syncsafe = chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
    $bad = sys_get_temp_dir() . '/mw-bad-' . bin2hex(random_bytes(4)) . '.mp3';
    file_put_contents($bad, "ID3\x03\x00\x00" . $syncsafe . $frames . str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 200));
    try {
        $ok = $writer->writeTags($bad, ['title' => 'New title']);
        $info = readTags($bad);
        check('malformed frames: write succeeds', $ok, (string) $writer->lastError());
        check('malformed frames: title updated', ($info['comments']['title'][0] ?? '') === 'New title');
        check('malformed frames: picture kept, mime fixed to image/jpeg', ($info['id3v2']['APIC'][0]['mime'] ?? '') === 'image/jpeg' && ($info['id3v2']['APIC'][0]['data'] ?? '') === $jpeg, json_encode($info['id3v2']['APIC'][0]['mime'] ?? null));
        check('malformed frames: GEOB kept with a generic mime', ($info['id3v2']['GEOB'][0]['objectdata'] ?? '') === 'OBJECT');
        check('malformed frames: empty WXXX dropped (getID3 drops it anyway)', !isset($info['id3v2']['WXXX']));
        $eng = array_values(array_filter($info['id3v2']['COMM'] ?? [], static fn(array $c): bool => strtolower((string) $c['language']) === 'eng'));
        check('malformed frames: duplicate COMM (same language + description) → first kept', count($eng) === 1 && ($eng[0]['data'] ?? '') === 'first comment', json_encode(array_column($info['id3v2']['COMM'] ?? [], 'data')));
        check('malformed frames: unwritable RVA2 (parsed without raw data) dropped, no warning', !isset($info['id3v2']['RVA2']));
        $langs = array_map(static fn(array $c): string => strtolower((string) $c['language']), $info['id3v2']['COMM'] ?? []);
        check('malformed frames: COMM with a garbage language code kept as "xxx" (unknown)', in_array('xxx', $langs, true), json_encode($langs));
    } finally {
        @unlink($bad);
    }

    check('missing file → false', $writer->writeTags($file . '.nope', ['title' => 'x']) === false);
} finally {
    @unlink($file);
}

echo $failures === 0 ? "\nAll writer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
