<?php

/*
 * Standalone test for RhythmChartGenerator::generateFromBeats (Phase 2 v3,
 * onset-first). Feeds a hand-crafted v3 grid with KNOWN properties and
 * asserts the gameplay contract:
 *   - big hits become taps at their exact times ("3 gros coups = 3 taps",
 *     including a fast double-kick run tighter than the tier refractory)
 *   - weak hits are dropped on easy but kept on harder tiers
 *   - simultaneous kick+snare become a musical 2-note chord on expert
 *   - onsets are snapped to the beat grid (de-jitter), holds never eat the
 *     next tap, output is deterministic, sparse grids fall back to Phase 1
 *
 * Run:  php tests/rhythm-generator-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\RhythmChartGenerator;

$gen = new RhythmChartGenerator();
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

/** @param list<array<string, mixed>> $notes */
function timesAt(array $notes, float $t, float $tol = 0.011): array
{
    return array_values(array_filter($notes, static fn(array $n): bool => abs((float) $n['time'] - $t) <= $tol));
}

// ---- Fixture: known musical scenario, duration 30 s -------------------------
$beats = [];
for ($t = 3.0; $t <= 16.0; $t += 0.5) {
    $beats[] = round($t, 3); // steady 120 BPM pulse
}

$low = [];
foreach ([3.0, 4.0, 5.0, 7.0, 8.0, 9.0, 13.0, 14.0, 15.0, 16.0] as $t) {
    $low[] = [$t, 0.9]; // big kicks on the beat
}
$low[] = [6.03, 0.9];                    // jittered kick -> must snap to 6.0
$low[] = [10.0, 0.95];                   // fast double-kick triple:
$low[] = [10.25, 0.95];                  //   0.25 s apart, tighter than easy's
$low[] = [10.5, 0.95];                   //   0.35 s refractory -> all 3 kept
$low[] = [11.5, 0.30];                   // quiet bass note (dropped on easy)
$low[] = [12.5, 0.30];                   // quiet bass note (dropped on easy)

$mid = [[13.0, 0.9]];                    // snare together with the 13.0 kick
$grid = ['v' => 3, 'bpm' => 120, 'beats' => $beats, 'bands' => ['low' => $low, 'mid' => $mid, 'high' => []]];

// ---- Easy: only the big hits, all of them ----------------------------------
$easy = $gen->generateFromBeats(42, 30, 'easy', $grid);
echo 'easy notes: ' . \count($easy) . "\n";

foreach ([10.0, 10.25, 10.5] as $t) {
    check("easy: big hit @{$t}s is a tap", \count(timesAt($easy, $t)) === 1, json_encode(timesAt($easy, $t, 0.06)) ?: '');
}
$run = array_values(array_filter($easy, static fn(array $n): bool => $n['time'] >= 9.9 && $n['time'] <= 10.6));
check('easy: 3 fast big kicks in a row -> exactly 3 taps', \count($run) === 3, json_encode($run) ?: '');
check('easy: quiet bass @11.5s dropped', timesAt($easy, 11.5) === []);
check('easy: quiet bass @12.5s dropped', timesAt($easy, 12.5) === []);
check('easy: jittered kick snapped 6.03 -> 6.0', \count(timesAt($easy, 6.0, 0.001)) === 1 && timesAt($easy, 6.03, 0.001) === []);
check('easy: single notes only (no chords)', \count(timesAt($easy, 13.0)) === 1);
// Fixed instrument lanes (Taiko-style): bass -> lane 1, snare -> lane 2 on 4 lanes.
$badLane = array_filter($easy, static fn(array $n): bool => !\in_array($n['lane'], [1, 2], true));
check('easy: fixed instrument lanes only (1=bass, 2=snare)', $badLane === [], json_encode(array_values($badLane)) ?: '');
$bassNotes = array_values(array_filter($easy, static fn(array $n): bool => abs((float) $n['time'] - 10.0) < 0.02));
check('easy: bass hits always on the bass lane', $bassNotes !== [] && $bassNotes[0]['lane'] === 1, json_encode($bassNotes) ?: '');

// ---- Expert: weak hits appear, kick+snare becomes a chord -------------------
$expert = $gen->generateFromBeats(42, 30, 'expert', $grid);
echo 'expert notes: ' . \count($expert) . "\n";

check('expert: quiet bass @11.5s kept', \count(timesAt($expert, 11.5)) === 1);
$chord = timesAt($expert, 13.0);
check('expert: kick+snare @13.0s -> 2-note chord', \count($chord) === 2, json_encode($chord) ?: '');
if (\count($chord) === 2) {
    $lanes = [$chord[0]['lane'], $chord[1]['lane']];
    sort($lanes);
    check('expert: chord = fixed bass lane (1) + fixed snare lane (3)', $lanes === [1, 3], json_encode($lanes) ?: '');
}

// ---- Holds never eat the next tap -------------------------------------------
foreach (['easy', 'medium', 'hard', 'expert', 'master', 'demon'] as $diff) {
    $notes = $gen->generateFromBeats(42, 30, $diff, $grid);
    $sorted = $notes;
    usort($sorted, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
    $ok = true;
    $detail = '';
    foreach ($sorted as $i => $n) {
        if (!empty($n['isLong'])) {
            // Find the next strictly later note time (any lane).
            for ($j = $i + 1; $j < \count($sorted); ++$j) {
                if ($sorted[$j]['time'] > $n['time'] + 0.001) {
                    if ((float) $n['endTime'] > (float) $sorted[$j]['time']) {
                        $ok = false;
                        $detail = json_encode([$n, $sorted[$j]]) ?: '';
                    }
                    break;
                }
            }
        }
    }
    check("{$diff}: no hold overlaps the next tap", $ok, $detail);
}

// ---- Density budget: a wall-of-onsets mix must stay playable ----------------
// (playtest 2026-08-22: fixed strength thresholds made hard unplayable on
// compressed masters — the notes/second budget must cap the chart density)
$denseLow = [];
for ($t = 3.0; $t < 117.0; $t += 0.1) {
    $denseLow[] = [round($t, 3), 0.5]; // 10 equal-strength onsets per second
}
$denseGrid = ['v' => 3, 'bpm' => null, 'beats' => [], 'bands' => ['low' => $denseLow, 'mid' => [], 'high' => []]];
$targets = ['easy' => 0.8, 'medium' => 1.4, 'hard' => 2.2, 'expert' => 3.2, 'master' => 4.4, 'demon' => 6.0];
foreach ($targets as $diff => $nps) {
    $notes = $gen->generateFromBeats(7, 120, $diff, $denseGrid);
    $actual = \count($notes) / 117.0;
    check(
        \sprintf('%s: dense mix capped near %.1f nps', $diff, $nps),
        $actual <= $nps * 1.1 && $actual >= min($nps, 1.0 / 0.45) * 0.4,
        \sprintf('actual=%.2f nps (%d notes)', $actual, \count($notes))
    );
}

// ---- Kick-lock + wide bass snap (musician-tight timing) ----------------------
// A bass attack detected 40 ms after a drum hit is the SAME musical event:
// the note adopts the drum's timestamp. A bass-only hit detected late (bass
// attacks smear) gets a wider snap window back onto the beat grid.
$lockBeats = [];
for ($t = 3.0; $t <= 29.0; $t += 0.5) {
    $lockBeats[] = round($t, 3);
}
$lockGrid = ['v' => 4, 'bpm' => 120, 'beats' => $lockBeats, 'bands' => [
    'low' => [[3.0, 0.9], [4.0, 0.9], [5.0, 0.9], [6.0, 0.9], [7.0, 0.9], [8.0, 0.9], [9.04, 0.9], [15.07, 0.9], [20.0, 0.9], [21.0, 0.9]],
    'mid' => [[9.0, 0.9]],
    'high' => [],
]];
$lockHard = $gen->generateFromBeats(9, 30, 'hard', $lockGrid);
$lockChord = timesAt($lockHard, 9.0, 0.001);
check('kick-lock: bass@9.04 + drum@9.00 -> chord at the DRUM time (9.0)', \count($lockChord) === 2, json_encode($lockChord) ?: '');
check('kick-lock: no stray note at the late bass time (9.04)', timesAt($lockHard, 9.04, 0.001) === []);
check('wide bass snap: late bass-only hit 15.07 pulled onto the beat (15.0)', \count(timesAt($lockHard, 15.0, 0.001)) === 1 && timesAt($lockHard, 15.07, 0.001) === []);

// ---- Determinism + fallback --------------------------------------------------
$again = $gen->generateFromBeats(42, 30, 'easy', $grid);
check('deterministic output for identical input', json_encode($easy) === json_encode($again));

$sparse = ['v' => 3, 'bpm' => null, 'beats' => [], 'bands' => ['low' => [[5.0, 0.9]], 'mid' => [], 'high' => []]];
$fallback = $gen->generateFromBeats(42, 30, 'easy', $sparse);
check('sparse grid falls back to Phase 1 (non-empty chart)', $fallback !== []);

$legacy = ['bpm' => 120, 'beats' => $beats, 'bass' => array_column($low, 0), 'drums' => []];
$fromLegacy = $gen->generateFromBeats(42, 30, 'easy', $legacy);
check('legacy v2 grid still generates a chart', $fromLegacy !== []);

exit($failures === 0 ? 0 : 1);
