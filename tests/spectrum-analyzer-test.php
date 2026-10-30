<?php

/*
 * Standalone test for the inflated-bitrate detection (SpectrumAnalyzer) on
 * synthetic signals: a sharp encoder wall at 17 kHz, full treble, a natural
 * roll-off (never a fake), silence; then the real bitrate per format and
 * declared bitrate, and the quality tier of an inflated file.
 *
 * Run:  php tests/spectrum-analyzer-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Enum\AudioQuality;
use App\Service\SpectrumAnalyzer;

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
 * 2 s of mono 16-bit PCM: one sine every 100 Hz up to $top Hz, random phases,
 * level falling by $slope dB per kHz (music has less treble than bass).
 */
function pcm(float $top, float $slope = 2.5, float $gain = 1.0): string
{
    mt_srand(42);
    $rate = 44100;
    $tones = [];
    for ($f = 100.0; $f <= $top; $f += 100.0) {
        $tones[] = [2 * M_PI * $f / $rate, mt_rand() / mt_getrandmax() * 2 * M_PI, 10 ** (-$slope * $f / 1000 / 20)];
    }
    $sum = array_sum(array_column($tones, 2));
    $out = '';
    for ($i = 0; $i < 2 * $rate; ++$i) {
        $v = 0.0;
        foreach ($tones as [$w, $phase, $amp]) {
            $v += $amp * sin($w * $i + $phase);
        }
        $out .= pack('v', (int) round(max(-1, min(1, $gain * 0.5 * $v / $sum * 8)) * 32767) & 0xFFFF);
    }

    return $out;
}

$analyzer = new SpectrumAnalyzer();

echo "Cutoff\n";
$wall = $analyzer->cutoff(pcm(17000));
check('wall at 17 kHz: sharp', $wall !== null && $wall['sharp'], json_encode($wall));
check('wall at 17 kHz: found within 300 Hz', $wall !== null && abs($wall['cutoffHz'] - 17000) <= 300, json_encode($wall));

$full = $analyzer->cutoff(pcm(22000, 1.5));
check('full treble: cutoff ≥ 21 kHz', $full !== null && $full['cutoffHz'] >= 21000, json_encode($full));

// A natural roll-off: the treble fades by 9 dB per kHz, no wall.
$soft = $analyzer->cutoff(pcm(22000, 9));
check('natural roll-off: not sharp', $soft !== null && !$soft['sharp'], json_encode($soft));
check('natural roll-off: never inflated', $soft !== null && SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, $soft['cutoffHz'], $soft['sharp']) === null);

echo "Slices (start, middle, end of the track)\n";
// Fake with a quiet middle passage: the wall is still found, the quiet slice does not vote.
$quiet = $analyzer->cutoff(pcm(17000) . pcm(17000, 2.5, 0.002) . pcm(17000), 3);
check('fake, quiet middle passage: still sharp at 17 kHz', $quiet !== null && $quiet['sharp'] && abs($quiet['cutoffHz'] - 17000) <= 300, json_encode($quiet));
// Genuine file whose middle passage stops at 16.5 kHz (a filtered synth): one slice out of three, not a fake.
$filtered = $analyzer->cutoff(pcm(22000, 1.5) . pcm(16500) . pcm(22000, 1.5), 3);
check('genuine, one filtered passage: not sharp', $filtered !== null && !$filtered['sharp'], json_encode($filtered));
// Genuine file with a near-silent passage: nothing to see there, not a fake.
$calm = $analyzer->cutoff(pcm(22000, 1.5) . pcm(21500, 1.5, 0.002) . pcm(22000, 1.5), 3);
check('genuine, calm passage: not sharp', $calm !== null && !$calm['sharp'], json_encode($calm));
// A wall missing from a loud passage is no encoder's (an encoder cuts everywhere): not a fake.
$twoOfThree = $analyzer->cutoff(pcm(17000) . pcm(22000, 1.5) . pcm(17000), 3);
check('wall missing from one loud passage: not sharp', $twoOfThree !== null && !$twoOfThree['sharp'], json_encode($twoOfThree));

check('silence → null',$analyzer->cutoff(str_repeat("\0\0", 44100 * 2)) === null);
check('too short → null', $analyzer->cutoff(str_repeat("\1\0", 100)) === null);

echo "Real bitrate\n";
check('MP3 320 cut at 17 kHz → 128', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 17000, true) === 128);
check('MP3 320 cut at 20.5 kHz → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 20500, true) === null);
check('MP3 320 cut at 19.1 kHz (older LAME) → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 19100, true) === null);
check('MP3 320 cut at 18.7 kHz → 192', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 18700, true) === 192);
check('MP3 256 cut at 19.7 kHz → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 256000, 19700, true) === null);
check('MP3 224 cut at 17 kHz → 128', SpectrumAnalyzer::effectiveKbps('mp3', false, 224000, 17000, true) === 128);
check('MP3 192 cut at 16 kHz (old FhG / Xing encoder) → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 192000, 16000, true) === null);
check('MP3 128 cut at 16 kHz → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 128000, 16000, true) === null);
check('MP3 160 cut at 14.6 kHz → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 160000, 14600, true) === null);
check('MP3 320 with a soft roll-off → genuine', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 16000, false) === null);
check('AAC 256 cut at 16 kHz → 112', SpectrumAnalyzer::effectiveKbps('mp4', false, 256000, 16000, true) === 112);
check('AAC 128 cut at 16 kHz → genuine (AAC 128 cuts there)', SpectrumAnalyzer::effectiveKbps('mp4', false, 128000, 16000, true) === null);
check('AAC 256 cut at 20 kHz → genuine', SpectrumAnalyzer::effectiveKbps('mp4', false, 256000, 20000, true) === null);
check('AAC 318 cut at 17.3 kHz → 160', SpectrumAnalyzer::effectiveKbps('mp4', false, 318000, 17300, true) === 160);
check('FLAC cut at 16 kHz → 112 (an MP3 in disguise)', SpectrumAnalyzer::effectiveKbps('flac', true, 900000, 16000, true) === 112);
check('FLAC cut at 19.5 kHz → genuine', SpectrumAnalyzer::effectiveKbps('flac', true, 900000, 19500, true) === null);
check('FLAC cut at 18.5 kHz → 192', SpectrumAnalyzer::effectiveKbps('flac', true, 900000, 18500, true) === 192);
check('not analysable (0 Hz) → null', SpectrumAnalyzer::effectiveKbps('mp3', false, 320000, 0, true) === null);

echo "Quality tier\n";
check('MP3 320 inflated to 128 → low', AudioQuality::classify('mp3', false, 320000, 128) === AudioQuality::Low);
check('FLAC inflated to 112 → very low', AudioQuality::classify('flac', true, 900000, 112) === AudioQuality::VeryLow);
check('genuine MP3 320 → very high', AudioQuality::classify('mp3', false, 320000, null) === AudioQuality::VeryHigh);

echo $failures === 0 ? "\nAll spectrum tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
