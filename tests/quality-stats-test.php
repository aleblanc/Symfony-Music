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
$mp3Real = array_column(array_column($real['formats'], null, 'key')['mp3']['buckets'], 'inflated', 'key');
check('MP3 320 range: 4 inflated (tier really lower)', $mp3Real === ['128–159 kb/s' => 0, '≥ 320 kb/s' => 4], json_encode($mp3Real, JSON_UNESCAPED_UNICODE));

// An MP3 224 (standard) whose cutoff says 192 (standard too): not lower, so not inflated either.
$same = (new QualityStats())->build([
    ['format' => 'mp3', 'lossless' => false, 'kbps' => 224, 'sampleRate' => 44100, 'bitsPerSample' => null, 'effectiveKbps' => 192, 'n' => 3],
]);
check('same tier: not counted as inflated nor lower', $same['formats'][0]['buckets'][0]['inflated'] === 0 && $same['declared'][0]['lower'] === 0, json_encode([$same['formats'][0]['buckets'][0]['inflated'], $same['declared'][0]['lower']]));

echo "Real spectrum (treble cutoff)\n";
$spectrum = (new QualityStats())->spectrum([
    ['hz' => -2, 'sharp' => null, 'n' => 7], ['hz' => -1, 'sharp' => null, 'n' => 2],
    // < 15.5 kHz: an old recording (natural roll-off) and one analysed before the distinction (counted as a wall, as it was).
    ['hz' => 10000, 'sharp' => false, 'n' => 1], ['hz' => 12000, 'sharp' => null, 'n' => 1],
    ['hz' => 16000, 'sharp' => true, 'n' => 3], ['hz' => 17000, 'sharp' => true, 'n' => 5], ['hz' => 20000, 'sharp' => true, 'n' => 6], ['hz' => 22000, 'sharp' => false, 'n' => 4],
]);
check('counts: analysed, not decodable, pending, unknown', $spectrum['analysed'] === 20 && $spectrum['failed'] === 2 && $spectrum['pending'] === 7 && $spectrum['unknown'] === 1, json_encode(array_slice($spectrum, 0, 4)));
$buckets = $spectrum['buckets'];
// One range per LAME lowpass: < 15.5 (96), 15.5–16.5 (112), 16.5–17.3 (128), 17.3–18 (160), 18–19 (192), 19–19.6 (224), 19.6–20.3 (256), ≥ 20.3 (320).
check('one range per MP3 bitrate, from < 15.5 to ≥ 20.3 kHz', \count($buckets) === 8 && (string) $buckets[0]['label'] === '< 15.5 kHz' && (string) $buckets[2]['label'] === '16.5–17.3 kHz' && (string) $buckets[4]['label'] === '18–19 kHz' && (string) $buckets[7]['label'] === '≥ 20.3 kHz', implode(', ', array_map(static fn($b) => (string) $b['label'], $buckets)));
check('counts per range', array_column($buckets, 'count') === [2, 3, 5, 0, 0, 0, 6, 4], json_encode(array_column($buckets, 'count')));
check('walls and natural roll-offs apart', array_column($buckets, 'sharp') === [1, 3, 5, 0, 0, 0, 6, 0] && array_column($buckets, 'soft') === [1, 0, 0, 0, 0, 0, 0, 4], json_encode([array_column($buckets, 'sharp'), array_column($buckets, 'soft')]));
check('bar shares: walls and roll-offs of the analysed ones', $buckets[0]['pctSharp'] === 5.0 && $buckets[0]['pctSoft'] === 5.0 && $buckets[0]['pct'] === 10.0 && $buckets[7]['pctSharp'] === 0.0 && $buckets[7]['pctSoft'] === 20.0, json_encode($buckets[0]));
check('the MP3 cut there', array_column($buckets, 'kbps') === [96, 112, 128, 160, 192, 224, 256, 320], json_encode(array_column($buckets, 'kbps')));
check('list bounds in Hz', $buckets[0]['min'] === null && $buckets[0]['max'] === 15499 && $buckets[1]['min'] === 15500 && $buckets[1]['max'] === 16499 && $buckets[7]['min'] === 20300 && $buckets[7]['max'] === null);
check('colour: that bitrate\'s tier, one per range', array_column($buckets, 'quality') === ['very_low', 'very_low', 'low', 'standard', 'standard', 'standard', 'high', 'very_high'], json_encode(array_column($buckets, 'quality')));
check('share of the analysed ones', $buckets[6]['pct'] === 30.0, (string) $buckets[6]['pct']);
check('label per locale: numbers, not text', $buckets[2]['label']->getParameters() === ['min' => 16.5, 'max' => 17.3] && $buckets[0]['label']->getParameters() === ['khz' => 15.5], json_encode($buckets[2]['label']->getParameters()));
check('list title from the bounds', (string) QualityStats::cutoffLabel(15500, 16499) === '15.5–16.5 kHz' && (string) QualityStats::cutoffLabel(null, 15499) === '< 15.5 kHz' && (string) QualityStats::cutoffLabel(20300, null) === '≥ 20.3 kHz');

echo "No audible difference (≈ 90 / 99 % of listeners)\n";
// The kHz follow the kb/s: 19 kHz is the lowpass of an MP3 192 (90 %), 20 kHz that of a 256 (99 %).
check('cutoff: from 19 kHz for 90 %, 20 kHz for 99 %', array_column($buckets, 'transparent') === [null, null, null, null, null, 90, 90, 99] && $spectrum['transparent'] === [90 => 19, 99 => 20], json_encode(array_column($buckets, 'transparent')));
$mp3Ranges = array_column($formats['mp3']['buckets'], 'transparent', 'key');
check('MP3 ranges: 128 and below never transparent, 320 for 99 %', $mp3Ranges === ['Unknown bitrate' => null, '< 64 kb/s' => null, '128–159 kb/s' => null, '≥ 320 kb/s' => 99], json_encode($mp3Ranges, JSON_UNESCAPED_UNICODE));
check('MP3 thresholds 192 / 256', $formats['mp3']['transparent'] === [90 => 192, 99 => 256]);
check('AAC thresholds 128 / 192, its 256 range for 99 %', $formats['mp4']['transparent'] === [90 => 128, 99 => 192] && array_column($formats['mp4']['buckets'], 'transparent', 'key')['256–319 kb/s'] === 99, json_encode($formats['mp4']));
check('Opus thresholds 96 / 128', QualityStats::transparentKbps('opus') === [90 => 96, 99 => 128]);
check('lossless: no threshold', $formats['flac']['transparent'] === null && array_column($formats['flac']['buckets'], 'transparent') === [null, null]);

echo $failures === 0 ? "\nAll quality stats tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
