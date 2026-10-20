<?php

/*
 * Standalone test for Import\LibraryPath: where an identified file goes in
 * MUSIC_DIR — Album Artist/Album (Year)/NN Title.ext, singles and
 * compilations under the track artist, multi-disc numbering, unsafe characters removed.
 *
 * Run:  php tests/import-path-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Import\LibraryPath;

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

$p = new LibraryPath();
$album = ['title' => 'Paranoid Android', 'artist' => 'Radiohead', 'album' => 'OK Computer', 'albumArtist' => 'Radiohead', 'year' => 1997, 'trackNumber' => 2, 'trackTotal' => 12, 'discNumber' => 1, 'discTotal' => 1];

check('album track', $p->relativePath($album, 'mp3') === 'Radiohead/OK Computer (1997)/02 Paranoid Android.mp3', $p->relativePath($album, 'mp3'));
check('extension lowercased', $p->relativePath($album, 'MP3') === 'Radiohead/OK Computer (1997)/02 Paranoid Android.mp3');
check('no year → no parentheses', $p->relativePath(['year' => null] + $album, 'flac') === 'Radiohead/OK Computer/02 Paranoid Android.flac');
check('more than 99 tracks → 3 digits', str_contains($p->relativePath(['trackNumber' => 7, 'trackTotal' => 120] + $album, 'mp3'), '/007 Paranoid Android.mp3'));

$disc = ['discNumber' => 2, 'discTotal' => 2, 'trackNumber' => 5] + $album;
check('multi-disc → D-NN prefix', $p->relativePath($disc, 'mp3') === 'Radiohead/OK Computer (1997)/2-05 Paranoid Android.mp3', $p->relativePath($disc, 'mp3'));

$comp = ['albumArtist' => 'Various Artists', 'artist' => 'Van Morrison', 'title' => 'Brown Eyed Girl', 'album' => 'NOW Movies', 'year' => 2013, 'trackNumber' => 19, 'trackTotal' => 20] + $album;
check('compilation → track artist folder', $p->relativePath($comp, 'mp3') === 'Van Morrison/NOW Movies (2013)/19 Brown Eyed Girl.mp3', $p->relativePath($comp, 'mp3'));

$single = ['title' => 'Snake Oil', 'artist' => 'Foals'];
check('no album → Artist/Singles/Title', $p->relativePath($single, 'mp3') === 'Foals/Singles/Snake Oil.mp3', $p->relativePath($single, 'mp3'));

$unsafe = ['albumArtist' => 'AC/DC', 'album' => 'Who Made Who: "Live"?', 'title' => 'Hell\'s Bells <remaster> | 2003*', 'trackNumber' => 1] + $album;
$path = $p->relativePath($unsafe, 'mp3');
check('slashes and reserved characters removed from every part', $path === "AC-DC/Who Made Who - 'Live' (1997)/01 Hell's Bells remaster - 2003.mp3", $path);
$t = $p->relativePath(['albumArtist' => '..', 'album' => '../../etc', 'title' => '..'] + $album, 'mp3');
check('no path traversal: 3 components, none is "." or ".."', count(explode('/', $t)) === 3 && array_intersect(explode('/', $t), ['.', '..']) === [], $t);
check('ellipsis kept in titles', str_contains($p->relativePath(['title' => '...And Justice for All'] + $album, 'mp3'), '/02 ...And Justice for All.mp3'), $p->relativePath(['title' => '...And Justice for All'] + $album, 'mp3'));
check('leading/trailing dots and spaces trimmed', str_starts_with($p->relativePath(['albumArtist' => ' .The Band. '] + $album, 'mp3'), 'The Band/'), $p->relativePath(['albumArtist' => ' .The Band. '] + $album, 'mp3'));
check('empty parts get a fallback', $p->relativePath(['albumArtist' => '', 'artist' => '', 'title' => ''] + $album, 'mp3') === 'Unknown Artist/OK Computer (1997)/02 Untitled.mp3', $p->relativePath(['albumArtist' => '', 'artist' => '', 'title' => ''] + $album, 'mp3'));
$long = $p->relativePath(['title' => str_repeat('Very Long Title ', 20)] + $album, 'mp3');
check('long names shortened (each part ≤ 120 bytes)', max(array_map('strlen', explode('/', $long))) <= 120, (string) max(array_map('strlen', explode('/', $long))));
check('decomposed accents recomposed (NFC)', $p->relativePath(['albumArtist' => Normalizer::normalize('Bérurier Noir', Normalizer::FORM_D)] + $album, 'mp3') === 'Bérurier Noir/OK Computer (1997)/02 Paranoid Android.mp3');

echo $failures === 0 ? "\nAll path tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
