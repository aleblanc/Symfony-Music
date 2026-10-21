<?php

/*
 * Standalone test for Import\FileImporter on real (generated) files: tags +
 * cover + MusicBrainz ids written, file moved to MUSIC_DIR/Artist/Album
 * (Year)/NN Title.mp3, cover.jpg added, emptied import folders removed,
 * identical file already in the library → duplicates folder, name clash →
 * numbered, failures leave the file where it was.
 *
 * Run:  php tests/import-filer-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\CoverImageProcessor;
use App\Service\Enrichment\Proposal;
use App\Service\Import\FileImporter;
use App\Service\Import\LibraryPath;
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

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$root = sys_get_temp_dir() . '/import-filer-test-' . bin2hex(random_bytes(4));
$import = "{$root}/import";
$music = "{$root}/music";
mkdir("{$import}/2019/Album inconnu", 0777, true);
mkdir($music);
$mp3 = static function (string $path, int $frames = 300): void {
    file_put_contents($path, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), $frames));
};
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$proposal = new Proposal(0, [
    'title' => 'Fitter Happier', 'artist' => 'Radiohead', 'album' => 'OK Computer', 'albumArtist' => 'Radiohead',
    'trackNumber' => 7, 'trackTotal' => 12, 'discNumber' => 1, 'discTotal' => 1, 'year' => 1997,
], 0.95, Proposal::SOURCE_ACOUSTID, 'rel-1', 'rec-7', [], null, 'rg-1');

$filer = new FileImporter(new MetadataWriter(__DIR__ . '/../lib/getid3'), new CoverImageProcessor(), new LibraryPath(), $music);

try {
    $src = "{$import}/2019/Album inconnu/Track 7.mp3";
    $mp3($src);
    $r = $filer->import($src, $import, $proposal, $jpeg);
    $dest = "{$music}/Radiohead/OK Computer (1997)/07 Fitter Happier.mp3";
    check('filed at Artist/Album (Year)/NN Title.mp3', $r->destination === $dest && is_file($dest), $r->destination);
    check('status imported', $r->status === 'imported');
    check('source removed', !is_file($src));
    check('emptied import folders removed, import root kept', !is_dir("{$import}/2019") && is_dir($import));
    check('cover.jpg next to the album', is_file("{$music}/Radiohead/OK Computer (1997)/cover.jpg"));

    $getID3 = new getID3();
    $info = $getID3->analyze($dest);
    getid3_lib::CopyTagsToComments($info);
    $txxx = [];
    foreach ($info['id3v2']['TXXX'] ?? [] as $t) {
        $txxx[$t['description']] = $t['data'];
    }
    check('tags written (title, track, year)', ($info['comments']['title'][0] ?? '') === 'Fitter Happier' && ($info['comments']['year'][0] ?? '') === '1997' && ($info['tags']['id3v2']['track_number'][0] ?? '') === '7');
    check('cover embedded', isset($info['comments']['picture'][0]['data']));
    check('MusicBrainz ids written', ($info['id3v2']['UFID'][0]['data'] ?? '') === 'rec-7' && ($txxx['MusicBrainz Album Id'] ?? '') === 'rel-1' && ($txxx['MusicBrainz Release Group Id'] ?? '') === 'rg-1', json_encode($txxx));

    // Same file again → duplicate (identical content already filed).
    mkdir("{$import}/again");
    copy($dest, "{$import}/again/copy.mp3");
    $r = $filer->import("{$import}/again/copy.mp3", $import, $proposal, $jpeg);
    check('identical file already in the library → duplicates folder', $r->status === 'duplicate' && str_starts_with($r->destination, "{$import}/_duplicates/") && is_file($r->destination), $r->destination);
    check('library file untouched by the duplicate', is_file($dest) && !is_file("{$music}/Radiohead/OK Computer (1997)/07 Fitter Happier (2).mp3"));

    // Different content, same destination → numbered, never overwritten.
    $other = "{$import}/other.mp3";
    $mp3($other, 500);
    $r = $filer->import($other, $import, $proposal, null);
    check('name clash with different content → " (2)"', $r->destination === "{$music}/Radiohead/OK Computer (1997)/07 Fitter Happier (2).mp3" && is_file($r->destination), $r->destination);

    // Unwritable tags: the file stays in the import folder.
    $broken = "{$import}/broken.mp3";
    file_put_contents($broken, 'not audio at all');
    chmod($broken, 0444);
    try {
        $filer->import($broken, $import, $proposal, null);
        check('tag failure throws', false);
    } catch (RuntimeException $e) {
        check('tag failure throws, file left in place', is_file($broken), $e->getMessage());
    }
    chmod($broken, 0644);

    // A file outside the import folder is refused.
    try {
        $filer->import(__FILE__, $import, $proposal, null);
        check('file outside the import folder refused', false);
    } catch (InvalidArgumentException) {
        check('file outside the import folder refused', true);
    }

    // Nothing identified it: kept as is under _unclassified, import path preserved.
    mkdir("{$import}/Skrillex/Unreleased", 0777, true);
    $src = "{$import}/Skrillex/Unreleased/01 Cat Rats.mp3";
    $mp3($src, 150);
    $before = md5_file($src);
    $r = $filer->fileUnclassified($src, $import);
    $dest = "{$music}/_unclassified/Skrillex/Unreleased/01 Cat Rats.mp3";
    check('unclassified: filed under _unclassified with its import path', $r->status === 'imported' && $r->destination === $dest && is_file($dest), $r->destination);
    check('unclassified: file untouched (no tags written)', md5_file($dest) === $before);
    check('unclassified: emptied import folders removed', !is_dir("{$import}/Skrillex"));
    mkdir("{$import}/Skrillex/Unreleased", 0777, true);
    $mp3($src, 150);
    $r = $filer->fileUnclassified($src, $import);
    check('unclassified: same audio again → duplicates folder', $r->status === 'duplicate' && str_starts_with($r->destination, "{$import}/_duplicates/_unclassified/"), $r->status . ' ' . $r->destination);
} finally {
    rrmdir($root);
}

echo $failures === 0 ? "\nAll filer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
