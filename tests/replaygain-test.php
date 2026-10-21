<?php

/*
 * Standalone test for ReplayGain::fromGetId3(): getID3's replay_gain block,
 * MP4 freeform comments, parsing of "-6.54 dB" strings, absurd values.
 *
 * Run:  php tests/replaygain-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\ReplayGain;

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

$rg = ReplayGain::fromGetId3(['replay_gain' => [
    'track' => ['adjustment' => -7.12, 'peak' => 0.988],
    'album' => ['adjustment' => -6.5],
]]);
check('getID3 block: track gain', $rg->trackGain === -7.12);
check('getID3 block: track peak', $rg->trackPeak === 0.988);
check('getID3 block: album gain', $rg->albumGain === -6.5);
check('getID3 block: no album peak', $rg->albumPeak === null);

$rg = ReplayGain::fromGetId3(['comments' => [
    'REPLAYGAIN_TRACK_GAIN' => ['-6.54 dB'],
    'replaygain_track_peak' => ['0.912345'],
    'replaygain_album_gain' => ['+1,20 dB'],
]]);
check('comments: "-6.54 dB"', $rg->trackGain === -6.54);
check('comments: peak string', $rg->trackPeak === 0.912345);
check('comments: "+1,20 dB" (comma)', $rg->albumGain === 1.2);

$rg = ReplayGain::fromGetId3(['replay_gain' => ['track' => ['adjustment' => 120.0, 'peak' => 0.0]], 'comments' => ['replaygain_album_gain' => ['n/a']]]);
check('absurd gain, zero peak and junk ignored', $rg->isEmpty() && $rg->trackPeak === null);
check('no data at all', ReplayGain::fromGetId3([])->isEmpty());

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
