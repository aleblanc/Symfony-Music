<?php

/*
 * Calibration of SpectrumAnalyzer's "expected cutoff" tables: where does each
 * encoder on this machine really cut a genuine file, per bitrate?
 *
 * Encodes 30 s of pink noise with every lossy encoder ffmpeg has (LAME, the
 * native AAC, FDK-AAC, Vorbis, Opus) at the usual bitrates, measures the treble
 * cutoff of each result and says whether SpectrumAnalyzer::effectiveKbps()
 * would wrongly call it inflated. A genuine encode must never be flagged: the
 * lowest sharp cutoff seen per codec family and declared bitrate, minus a
 * margin, is the floor to put in EXPECTED_MP3 / EXPECTED_EFFICIENT.
 *
 * Run:  php tests/spectrum-calibration.php [--keep]   (needs ffmpeg; --keep leaves the files in the temp dir)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BinaryLocator;
use App\Service\SpectrumAnalyzer;
use Symfony\Component\Process\Process;

const SECONDS = 30;
const MARGIN_HZ = 500;

$ffmpeg = (new BinaryLocator())->find('ffmpeg', (string) getenv('FFMPEG_PATH'));
if ($ffmpeg === null) {
    fwrite(STDERR, "ffmpeg not found: install it or set FFMPEG_PATH\n");
    exit(2);
}
$keep = \in_array('--keep', $_SERVER['argv'] ?? [], true);

$encoders = (new Process([$ffmpeg, '-hide_banner', '-encoders']))->mustRun()->getOutput();
$has = static fn(string $name): bool => preg_match('/^\s*A\S*\s+' . preg_quote($name, '/') . '\s/m', $encoders) === 1;

/**
 * Every encode to try: [codec family for the tables, ffmpeg encoder, declared kbps, extra ffmpeg args, container extension].
 * Family 'mp3' is judged with EXPECTED_MP3, 'efficient' with EXPECTED_EFFICIENT.
 *
 * @var list<array{0: string, 1: string, 2: int, 3: list<string>, 4: string, 5: string}> $plan family, encoder, kbps, args, extension, format as getID3 reports it
 */
$plan = [];
foreach ([128, 192, 224, 256, 320] as $k) {
    $plan[] = ['mp3', 'libmp3lame', $k, ['-b:a', $k . 'k'], 'mp3', 'mp3'];
}
foreach ([[2, 190], [0, 245]] as [$q, $k]) {
    $plan[] = ['mp3', 'libmp3lame', $k, ['-q:a', (string) $q], 'mp3', 'mp3']; // -V2 ≈ 190 kb/s, -V0 ≈ 245
}
foreach ([128, 192, 256] as $k) {
    $plan[] = ['efficient', 'aac', $k, ['-b:a', $k . 'k'], 'm4a', 'mp4'];
    $plan[] = ['efficient', 'libfdk_aac', $k, ['-b:a', $k . 'k'], 'm4a', 'mp4'];
    $plan[] = ['efficient', 'libvorbis', $k, ['-b:a', $k . 'k'], 'ogg', 'vorbis'];
    $plan[] = ['efficient', 'libopus', $k, ['-b:a', $k . 'k'], 'opus', 'opus'];
}
foreach ([[4, 128], [5, 192]] as [$mode, $k]) {
    $plan[] = ['efficient', 'libfdk_aac', $k, ['-vbr', (string) $mode], 'm4a', 'mp4']; // FDK VBR 4 ≈ 128 kb/s stereo, 5 ≈ 192
}
foreach ([[5, 160], [6, 192], [8, 256]] as [$q, $k]) {
    $plan[] = ['efficient', 'libvorbis', $k, ['-q:a', (string) $q], 'ogg', 'vorbis'];
}

$dir = sys_get_temp_dir() . '/spectrum-calibration-' . getmypid();
mkdir($dir, 0700, true);
$source = $dir . '/source.wav';
echo "Source: {$source} (pink noise, stereo, 44.1 kHz, " . SECONDS . " s)\n";
(new Process([$ffmpeg, '-v', 'error', '-nostdin', '-f', 'lavfi', '-i', 'anoisesrc=c=pink:r=44100:a=0.5', '-t', (string) SECONDS, '-ac', '2', '-c:a', 'pcm_s16le', $source]))->mustRun();

$analyzer = new SpectrumAnalyzer($ffmpeg);
$sourceCutoff = $analyzer->analyze($source, SECONDS);
printf("%-10s %-12s %8s  %-28s %10s  %-6s  %s\n", 'family', 'encoder', 'kbps', 'args', 'cutoff', 'sharp', 'verdict');
printf("%-10s %-12s %8s  %-28s %10s  %-6s  %s\n", 'source', 'pcm', '-', '', $sourceCutoff !== null ? number_format($sourceCutoff['cutoffHz'] / 1000, 1) . ' kHz' : '?', $sourceCutoff !== null && $sourceCutoff['sharp'] ? 'yes' : 'no', '');

/** @var array<string, array<int, int>> $lowest family → declared kbps → lowest sharp cutoff seen */
$lowest = [];
$falsePositives = 0;
$skipped = [];
foreach ($plan as [$family, $encoder, $kbps, $args, $ext, $format]) {
    if (!$has($encoder)) {
        $skipped[$encoder] = true;
        continue;
    }
    $out = \sprintf('%s/%s-%d-%s.%s', $dir, $encoder, $kbps, preg_replace('/[^a-z0-9]+/i', '', implode('', $args)), $ext);
    $encode = new Process([$ffmpeg, '-v', 'error', '-nostdin', '-i', $source, '-c:a', $encoder, ...$args, $out]);
    $encode->run();
    if (!$encode->isSuccessful()) {
        printf("%-10s %-12s %8d  %-28s %s\n", $family, $encoder, $kbps, implode(' ', $args), 'encode failed: ' . trim($encode->getErrorOutput()));
        continue;
    }
    $result = $analyzer->analyze($out, SECONDS);
    if ($result === null) {
        printf("%-10s %-12s %8d  %-28s %s\n", $family, $encoder, $kbps, implode(' ', $args), 'could not be analysed');
        continue;
    }
    $effective = SpectrumAnalyzer::effectiveKbps($format, false, $kbps * 1000, $result['cutoffHz'], $result['sharp']);
    $verdict = $effective === null ? 'genuine' : \sprintf('FALSE POSITIVE: called an MP3 %d', $effective);
    $falsePositives += $effective === null ? 0 : 1;
    if ($result['sharp']) {
        $lowest[$family][$kbps] = min($lowest[$family][$kbps] ?? PHP_INT_MAX, $result['cutoffHz']);
    }
    printf("%-10s %-12s %8d  %-28s %10s  %-6s  %s\n", $family, $encoder, $kbps, implode(' ', $args), number_format($result['cutoffHz'] / 1000, 1) . ' kHz', $result['sharp'] ? 'yes' : 'no', $verdict);
}

if ($skipped !== []) {
    echo "\nNot in this ffmpeg: " . implode(', ', array_keys($skipped)) . "\n";
}

echo "\nLowest sharp cutoff of a genuine encode, per family and declared bitrate (the floor to allow, minus " . MARGIN_HZ . " Hz):\n";
foreach ($lowest as $family => $byKbps) {
    ksort($byKbps);
    // A declared bitrate must also allow everything a lower one allows: the floor never rises with the bitrate going down.
    $floor = PHP_INT_MAX;
    $lines = [];
    foreach (array_reverse($byKbps, true) as $kbps => $hz) {
        $floor = min($floor, $hz);
        $lines[$kbps] = $floor;
    }
    ksort($lines);
    foreach ($lines as $kbps => $hz) {
        printf("  %-10s ≥ %3d kb/s: cuts no lower than %5.1f kHz → floor [%d, %d]\n", $family, $kbps, $hz / 1000, $kbps, max(0, $hz - MARGIN_HZ));
    }
}
echo "\nCompare with SpectrumAnalyzer::EXPECTED_MP3 / EXPECTED_EFFICIENT: a floor there must not be above the measured cutoff of a genuine encode.\n";
echo $falsePositives === 0 ? "No genuine encode was called inflated.\n" : "{$falsePositives} genuine encode(s) called inflated: lower the floors.\n";

if ($keep) {
    echo "Files kept in {$dir}\n";
} else {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
exit($falsePositives === 0 ? 0 : 1);
