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

echo "Declared → real\n";
// 10 MP3 320: 4 of them really 128; 3 FLAC: 1 really 112; 5 MP3 128 genuine.
$real = (new QualityStats())->build([
    ['format' => 'mp3', 'lossless' => false, 'kbps' => 320, 'sampleRate' => 44100, 'bitsPerSample' => null, 'effectiveKbps' => null, 'n' => 6],
    ['format' => 'mp3', 'lossless' => false, 'kbps' => 320, 'sampleRate' => 44100, 'bitsPerSample' => null, 'effectiveKbps' => 128, 'n' => 4],
    ['format' => 'flac', 'lossless' => true, 'kbps' => 900, 'sampleRate' => 44100, 'bitsPerSample' => 16, 'effectiveKbps' => null, 'n' => 2],
    ['format' => 'flac', 'lossless' => true, 'kbps' => 900, 'sampleRate' => 44100, 'bitsPerSample' => 16, 'effectiveKbps' => 112, 'n' => 1],
    ['format' => 'mp3', 'lossless' => false, 'kbps' => 128, 'sampleRate' => 44100, 'bitsPerSample' => null, 'effectiveKbps' => null, 'n' => 5],
]);
$declared = array_column($real['declared'], null, 'key');
check('declared tiers present, worst first', array_keys($declared) === ['low', 'very_high', 'lossless'], json_encode(array_keys($declared)));
check('MP3 320: 4 of 10 really low', $declared['very_high']['count'] === 10 && $declared['very_high']['lower'] === 4 && array_column($declared['very_high']['real'], 'count', 'key') === ['low' => 4], json_encode($declared['very_high']));
check('FLAC: 1 of 3 really very low', $declared['lossless']['lower'] === 1 && array_column($declared['lossless']['real'], 'count', 'key') === ['very_low' => 1], json_encode($declared['lossless']));
check('genuine tier: nothing lower', $declared['low']['lower'] === 0 && $declared['low']['real'] === [], json_encode($declared['low']));
check('tiers count the real tier', array_column($real['tiers'], 'count', 'key')['low'] === 9, json_encode(array_column($real['tiers'], 'count', 'key')));

echo "Real spectrum (treble cutoff)\n";
$spectrum = (new QualityStats())->spectrum([
    ['khz' => -2, 'n' => 7], ['khz' => -1, 'n' => 2],
    ['khz' => 10, 'n' => 1], ['khz' => 12, 'n' => 1], ['khz' => 16, 'n' => 3], ['khz' => 17, 'n' => 5], ['khz' => 20, 'n' => 6], ['khz' => 22, 'n' => 4],
]);
check('counts: analysed, not decodable, pending', $spectrum['analysed'] === 20 && $spectrum['failed'] === 2 && $spectrum['pending'] === 7, json_encode(array_slice($spectrum, 0, 3)));
$buckets = $spectrum['buckets'];
check('1 kHz ranges from < 13 to ≥ 21 kHz', \count($buckets) === 10 && (string) $buckets[0]['label'] === '< 13 kHz' && (string) $buckets[4]['label'] === '16–17 kHz' && (string) $buckets[9]['label'] === '≥ 21 kHz', implode(', ', array_map(static fn($b) => (string) $b['label'], $buckets)));
check('ends grouped', $buckets[0]['count'] === 2 && $buckets[9]['count'] === 4, json_encode([$buckets[0]['count'], $buckets[9]['count']]));
check('list bounds', $buckets[0]['min'] === null && $buckets[0]['max'] === 12 && $buckets[4]['min'] === 16 && $buckets[4]['max'] === 16 && $buckets[9]['min'] === 21 && $buckets[9]['max'] === null);
// 16–17 kHz: an MP3 112; 17–18: a 128 (LAME cuts it at 17.0); 18–19: a 160; 19–20: a 224; 20–21: a 256; ≥ 21: a 320.
check('colour: tier of the MP3 cut at the range start', array_column($buckets, 'quality') === ['very_low', 'very_low', 'very_low', 'very_low', 'very_low', 'low', 'standard', 'standard', 'high', 'very_high'], json_encode(array_column($buckets, 'quality')));
check('share of the analysed ones', $buckets[8]['pct'] === 30.0, (string) $buckets[8]['pct']);

echo "No audible difference (≈ 90 / 99 % of listeners)\n";
check('cutoff: from 17 kHz for 90 %, 19 kHz for 99 %', array_column($buckets, 'transparent') === [null, null, null, null, null, 90, 90, 99, 99, 99] && $spectrum['transparent'] === [90 => 17, 99 => 19], json_encode(array_column($buckets, 'transparent')));
$mp3Ranges = array_column($formats['mp3']['buckets'], 'transparent', 'key');
check('MP3 ranges: 128 and below never transparent, 320 for 99 %', $mp3Ranges === ['Unknown bitrate' => null, '< 64 kb/s' => null, '128–159 kb/s' => null, '≥ 320 kb/s' => 99], json_encode($mp3Ranges, JSON_UNESCAPED_UNICODE));
check('MP3 thresholds 192 / 256', $formats['mp3']['transparent'] === [90 => 192, 99 => 256]);
check('AAC thresholds 128 / 192, its 256 range for 99 %', $formats['mp4']['transparent'] === [90 => 128, 99 => 192] && array_column($formats['mp4']['buckets'], 'transparent', 'key')['256–319 kb/s'] === 99, json_encode($formats['mp4']));
check('Opus thresholds 96 / 128', QualityStats::transparentKbps('opus') === [90 => 96, 99 => 128]);
check('lossless: no threshold', $formats['flac']['transparent'] === null && array_column($formats['flac']['buckets'], 'transparent') === [null, null]);

echo $failures === 0 ? "\nAll quality stats tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
