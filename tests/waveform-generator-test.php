<?php

/*
 * Standalone test for the player waveform (WaveformGenerator): bars and
 * bands from a synthetic envelope, a short or empty one; then, when ffmpeg
 * is installed, a real file of a bass, a mid and a treble tone and the
 * cache beside it.
 *
 * Run:  php tests/waveform-generator-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BinaryLocator;
use App\Service\WaveformGenerator;

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

/**
 * Envelope frames as ffmpeg writes them: 4 little-endian floats per frame
 * (full, low, mid, high).
 *
 * @param list<array{float, float, float, float}> $frames
 */
function envelope(array $frames): string
{
    return pack('g*', ...array_merge(...$frames));
}

/** The band (low, mid, high) loudest in bar $i. */
function dominant(array $w, int $i): string
{
    $bands = ['low' => $w['low'][$i], 'mid' => $w['mid'][$i], 'high' => $w['high'][$i]];
    arsort($bands);

    return (string) array_key_first($bands);
}

echo "Envelope → bars\n";
// 300 frames (6 s): bass, mid, treble, then half-level bass.
$frames = [];
for ($f = 0; $f < 300; ++$f) {
    $frames[] = match (intdiv($f, 75)) {
        0 => [0.4, 0.4, 0.01, 0.0],
        1 => [0.4, 0.01, 0.4, 0.0],
        2 => [0.4, 0.0, 0.01, 0.4],
        default => [0.2, 0.2, 0.0, 0.0],
    };
}
$w = WaveformGenerator::fromEnvelope(envelope($frames), 100);
check('100 bars', $w !== null && $w['bars'] === 100 && \count($w['peak']) === 100 && \count($w['low']) === 100, json_encode($w['bars'] ?? null));
check('loudest bar = 100', $w !== null && max($w['peak']) === 100);
check('half level = 50', $w !== null && $w['peak'][90] === 50, (string) ($w['peak'][90] ?? '?'));
check('bass first', $w !== null && dominant($w, 10) === 'low');
check('mid second', $w !== null && dominant($w, 35) === 'mid');
check('treble third', $w !== null && dominant($w, 60) === 'high');
check('values are 0-100 integers', $w !== null && array_filter([...$w['peak'], ...$w['low'], ...$w['mid'], ...$w['high']], static fn ($v): bool => !\is_int($v) || $v < 0 || $v > 100) === []);

$short = WaveformGenerator::fromEnvelope(envelope(\array_slice($frames, 0, 40)), 100);
check('fewer frames than bars: one bar per frame', $short !== null && $short['bars'] === 40, json_encode($short['bars'] ?? null));
check('empty envelope: null', WaveformGenerator::fromEnvelope('', 100) === null);
check('silence: null', WaveformGenerator::fromEnvelope(envelope(array_fill(0, 50, [0.0, 0.0, 0.0, 0.0])), 100) === null);
check('negative float noise read as silence', WaveformGenerator::fromEnvelope(envelope(array_fill(0, 50, [-0.001, -0.001, 0.0, 0.0])), 100) === null);

$ffmpeg = (new BinaryLocator())->find('ffmpeg');
if ($ffmpeg === null) {
    echo "\nffmpeg not found: real file and cache skipped\n";
} else {
    echo "\nReal file (ffmpeg)\n";
    $dir = sys_get_temp_dir() . '/waveform-test-' . getmypid();
    @mkdir($dir . '/var', 0777, true);
    $mp3 = $dir . '/tones.mp3';
    $make = new Symfony\Component\Process\Process([$ffmpeg, '-v', 'error', '-y',
        '-f', 'lavfi', '-i', 'sine=f=80:d=2', '-f', 'lavfi', '-i', 'sine=f=1000:d=2', '-f', 'lavfi', '-i', 'sine=f=8000:d=2',
        '-filter_complex', '[0:a][1:a][2:a]concat=n=3:v=0:a=1', '-ar', '44100', '-b:a', '192k', $mp3]);
    $make->run();
    check('test file made', $make->isSuccessful() && is_file($mp3), $make->getErrorOutput());

    $generator = new WaveformGenerator($dir, $ffmpeg);
    $w = $generator->generate($mp3);
    check('waveform read', $w !== null);
    if ($w !== null) {
        $n = $w['bars'];
        check('bass in the first third', dominant($w, intdiv($n, 6)) === 'low');
        check('mid in the second third', dominant($w, intdiv($n, 2)) === 'mid');
        check('treble in the last third', dominant($w, intdiv(5 * $n, 6)) === 'high');
    }
    check('unreadable file: null', $generator->generate($dir . '/missing.mp3') === null);

    echo "\nCache\n";
    $first = $generator->forFile(7, $mp3);
    $cache = $dir . '/var/waveforms/7.json';
    check('written to var/waveforms/7.json', $first !== null && is_file($cache));
    file_put_contents($cache, json_encode(['bars' => 1, 'peak' => [1], 'low' => [1], 'mid' => [1], 'high' => [1]]));
    touch($cache, filemtime($mp3) + 10);
    clearstatcache();
    check('cache newer than the file: reused', ($generator->forFile(7, $mp3)['bars'] ?? null) === 1);
    touch($mp3, filemtime($cache) + 10);
    clearstatcache();
    check('file changed since: generated again', ($generator->forFile(7, $mp3)['bars'] ?? null) === $first['bars']);

    array_map('unlink', glob($dir . '/var/waveforms/*') ?: []);
    @rmdir($dir . '/var/waveforms');
    @rmdir($dir . '/var');
    @unlink($mp3);
    @rmdir($dir);
}

echo $failures === 0 ? "\nAll good\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
