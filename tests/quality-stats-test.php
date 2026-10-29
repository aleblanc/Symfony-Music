<?php

/*
 * Unit test for QualityStats: tiers, formats and bitrate ranges, worst first.
 *
 * Run:  php tests/quality-stats-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Enum\AudioQuality;
use App\Service\QualityStats;

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

$row = static fn(?string $format, ?bool $lossless, ?int $kbps, int $n, ?int $rate = 44100, ?int $bits = null) => ['format' => $format, 'lossless' => $lossless, 'kbps' => $kbps, 'sampleRate' => $rate, 'bitsPerSample' => $bits, 'n' => $n];

$stats = (new QualityStats())->build([
    $row('mp3', false, 320, 10),
    $row('mp3', false, 128, 5),
    $row('mp3', false, 131, 2),
    $row('mp3', false, 48, 1),
    $row('mp4', false, 256, 4),
    $row('mp4', false, 96, 3),
    $row('mp4', true, 900, 1, 44100, 16),
    $row('flac', true, 1000, 6, 44100, 16),
    $row('flac', true, 2500, 2, 96000, 24),
    $row('mp3', false, null, 1),
    $row(null, null, null, 3),
]);

$tiers = array_column($stats['tiers'], 'count', 'key');
check('total and not analysed', $stats['total'] === 38 && $stats['unscanned'] === 3, json_encode([$stats['total'], $stats['unscanned']]));
check('tiers worst → best', array_keys($tiers) === ['unknown', 'very_low', 'low', 'standard', 'high', 'very_high', 'lossless'], json_encode(array_keys($tiers)));
check('tier counts (MP3 48 = very low, MP3 128 = low, MP3 320 = very high, AAC 96 = standard, AAC 256 = very high)', $tiers === ['unknown' => 1, 'very_low' => 1, 'low' => 7, 'standard' => 3, 'high' => 0, 'very_high' => 14, 'lossless' => 9], json_encode($tiers));
// MP3 320 and AAC 256 cannot be told apart by ear: same tier.
foreach ([[192, 'standard'], [255, 'standard'], [256, 'high'], [288, 'high'], [318, 'very_high'], [320, 'very_high']] as [$kbps, $tier]) {
    check("MP3 {$kbps} kb/s = {$tier}", AudioQuality::classify('mp3', false, $kbps * 1000)?->value === $tier);
}
check('AAC 256 = MP3 320', AudioQuality::classify('mp4', false, 256000) === AudioQuality::classify('mp3', false, 320000));

$formats = array_column($stats['formats'], null, 'key');
check('formats: lossy first, biggest first', array_keys($formats) === ['mp3', 'mp4', 'flac', 'mp4-lossless'], json_encode(array_keys($formats)));
check('labels', $formats['mp4']['label'] === 'M4A (AAC)' && $formats['mp4-lossless']['label'] === 'M4A (ALAC)', $formats['mp4']['label'] . ' / ' . $formats['mp4-lossless']['label']);

$mp3 = array_column($formats['mp3']['buckets'], 'count', 'key');
check('MP3 ranges worst → best, 128 and 131 together', $mp3 === ['Unknown bitrate' => 1, '< 64 kb/s' => 1, '128–159 kb/s' => 7, '≥ 320 kb/s' => 10], json_encode($mp3, JSON_UNESCAPED_UNICODE));
$range = $formats['mp3']['buckets'][2];
check('range bounds for the track list', $range['min'] === 128 && $range['max'] === 159 && $range['quality'] === 'low', json_encode($range));
check('very low range', $formats['mp3']['buckets'][1]['quality'] === 'very_low', json_encode($formats['mp3']['buckets'][1]));
check('share inside the format', $formats['mp3']['buckets'][3]['pct'] === 52.6, (string) $formats['mp3']['buckets'][3]['pct']);

$flac = array_column($formats['flac']['buckets'], 'count', 'key');
check('FLAC by resolution, hi-res last', $flac === ['16 bit / 44.1 kHz' => 6, '24 bit / 96 kHz (hi-res)' => 2], json_encode($flac, JSON_UNESCAPED_UNICODE));

echo $failures === 0 ? "\nAll quality stats tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
