<?php

/*
 * Integration test for Import\WmaConverter with a real ffmpeg (skipped when
 * none is found; FFMPEG_PATH=/path/to/ffmpeg to point at one). A tagged WMA
 * becomes an M4A (AAC) next to it, tags kept, bitrate chosen from the source,
 * the original moved to IMPORT_DIR/_wma; a second run leaves it alone.
 *
 * Run:  php tests/import-convert-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BinaryLocator;
use App\Service\Import\WmaConverter;
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

$converter = new WmaConverter(new BinaryLocator(), (string) getenv('FFMPEG_PATH'));
$ffmpeg = $converter->binaryPath();
if ($ffmpeg === null) {
    echo "  --  ffmpeg not found: skipping (brew install ffmpeg, or FFMPEG_PATH=...)\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/import-convert-test-' . bin2hex(random_bytes(4));
$dir = "{$root}/Muse/The Resistance";
mkdir($dir, 0777, true);
$wma = "{$dir}/01 - Uprising.wma";
$make = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=6',
    '-c:a', 'wmav2', '-b:a', '192k', '-metadata', 'title=Uprising', '-metadata', 'artist=Muse', '-metadata', 'album=The Resistance',
    '-metadata', 'track=1/11', '-metadata', 'date=2009', $wma]);
$make->run();

try {
    check('fixture WMA created', $make->isSuccessful() && is_file($wma), $make->getErrorOutput());
    check('WMA files listed (originals folder excluded)', $converter->findWma($root) === [$wma], json_encode($converter->findWma($root)));

    $plan = $converter->plan($wma, null);
    check('lossy WMA → AAC, 192 kb/s source → 256 kb/s', !$plan['lossless'] && $plan['kbps'] === 256 && $plan['target'] === "{$dir}/01 - Uprising.m4a", json_encode($plan));
    check('forced bitrate', $converter->plan($wma, 320)['kbps'] === 320);

    $target = $converter->convert($wma, $root, null);
    check('converted next to the original', $target === "{$dir}/01 - Uprising.m4a" && is_file($target), $target);
    check('original moved to IMPORT_DIR/_wma (same relative path)', !is_file($wma) && is_file("{$root}/_wma/Muse/The Resistance/01 - Uprising.wma"));

    $getID3 = new getID3();
    $getID3->setOption(['encoding' => 'UTF-8']);
    $info = $getID3->analyze($target);
    getid3_lib::CopyTagsToComments($info);
    $c = $info['comments'] ?? [];
    check('M4A (AAC) output', ($info['fileformat'] ?? '') === 'mp4' && ($info['audio']['dataformat'] ?? '') === 'mp4', json_encode([$info['fileformat'] ?? null, $info['audio']['dataformat'] ?? null]));
    check('tags kept: title, artist, album', ($c['title'][0] ?? '') === 'Uprising' && ($c['artist'][0] ?? '') === 'Muse' && ($c['album'][0] ?? '') === 'The Resistance', json_encode([$c['title'] ?? null, $c['artist'] ?? null, $c['album'] ?? null]));
    check('tags kept: track number', (int) ($c['track_number'][0] ?? 0) === 1, json_encode($c['track_number'] ?? null));
    check('same duration', abs((float) ($info['playtime_seconds'] ?? 0) - 6.0) < 0.5, (string) ($info['playtime_seconds'] ?? ''));
    check('nothing left to convert', $converter->findWma($root) === []);

    // A target that already exists is never overwritten.
    mkdir("{$root}/Again");
    copy("{$root}/_wma/Muse/The Resistance/01 - Uprising.wma", "{$root}/Again/x.wma");
    touch("{$root}/Again/x.m4a");
    try {
        $converter->convert("{$root}/Again/x.wma", $root, null);
        check('existing target refused', false);
    } catch (RuntimeException $e) {
        check('existing target refused, WMA left in place', is_file("{$root}/Again/x.wma") && filesize("{$root}/Again/x.m4a") === 0, $e->getMessage());
    }
} finally {
    rrmdir($root);
}

echo $failures === 0 ? "\nAll convert tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
