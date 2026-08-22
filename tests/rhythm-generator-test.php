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

$mid = [[11.0, 0.9], [13.0, 0.9]];       // lone snare @11.0, snare with the 13.0 kick
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
// Instrument zones on 4 lanes: bass -> {0,1}, snare -> {2,3}, alternating so
// every key gets used (playtest 2026-08-22: one lane per instrument left
// half the keys dead on medium/hard).
$bassZone = array_values(array_filter($easy, static fn(array $n): bool => abs((float) $n['time'] - 13.0) >= 0.02 && abs((float) $n['time'] - 11.0) >= 0.02));
$badBass = array_filter($bassZone, static fn(array $n): bool => !\in_array($n['lane'], [0, 1], true));
check('easy: bass hits stay in the bass zone {0,1}', $badBass === [], json_encode(array_values($badBass)) ?: '');
$snareNotes = timesAt($easy, 11.0);
check('easy: lone snare lands in the drum zone {2,3}', $snareNotes !== [] && \in_array($snareNotes[0]['lane'], [2, 3], true), json_encode($snareNotes) ?: '');
$run = array_values(array_filter($easy, static fn(array $n): bool => $n['time'] >= 9.9 && $n['time'] <= 10.6));
$runLanes = array_map(static fn(array $n): int => (int) $n['lane'], $run);
check('easy: fast bass run alternates lanes inside the zone', \count($runLanes) === 3 && $runLanes[0] !== $runLanes[1] && $runLanes[1] !== $runLanes[2], json_encode($runLanes) ?: '');
$easyLanesUsed = array_values(array_unique(array_map(static fn(array $n): int => (int) $n['lane'], $easy)));
sort($easyLanesUsed);
check('easy: both bass-zone keys get used', \in_array(0, $easyLanesUsed, true) && \in_array(1, $easyLanesUsed, true), json_encode($easyLanesUsed) ?: '');

// ---- Expert: weak hits appear, kick+snare becomes a chord -------------------
$expert = $gen->generateFromBeats(42, 30, 'expert', $grid);
echo 'expert notes: ' . \count($expert) . "\n";

check('expert: quiet bass @11.5s kept', \count(timesAt($expert, 11.5)) === 1);
$chord = timesAt($expert, 13.0);
check('expert: kick+snare @13.0s -> 2-note chord', \count($chord) === 2, json_encode($chord) ?: '');
if (\count($chord) === 2) {
    $lanes = [$chord[0]['lane'], $chord[1]['lane']];
    sort($lanes);
    // This fixture has no hats, so the 6 lanes split adaptively 3+3:
    // bass zone {0,1,2}, drum zone {3,4,5} — one note from each zone.
    $inZones = \in_array($lanes[0], [0, 1, 2], true) && \in_array($lanes[1], [3, 4, 5], true);
    check('expert: chord = one bass-zone note + one drum-zone note', $inZones, json_encode($lanes) ?: '');
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

// ---- No pattern holes (playtest 2026-08-22: "des trous dans la batterie") ----
// (a) Budget pressure on one even run must THIN it uniformly (every k-th
// note = the same groove at a simpler subdivision), never punch random holes.
$runLow = [];
for ($t = 3.0; $t < 28.0; $t += 0.25) {
    $runLow[] = [round($t, 3), 0.8]; // 100 equal kicks, 0.25 s apart
}
$runGrid = ['v' => 4, 'bpm' => null, 'beats' => [], 'bands' => ['low' => $runLow, 'mid' => [], 'high' => []]];
$runMed = $gen->generateFromBeats(11, 30, 'medium', $runGrid);
usort($runMed, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
$gaps = [];
for ($i = 1; $i < \count($runMed); ++$i) {
    $gaps[] = round((float) $runMed[$i]['time'] - (float) $runMed[$i - 1]['time'], 3);
}
$uniform = $gaps !== [] && \count(array_unique($gaps)) === 1;
check('medium: over-budget even run is thinned uniformly (no random holes)', $uniform, json_encode(array_slice(array_values(array_unique($gaps)), 0, 6)) ?: '');
check('medium: thinned run stays within the nps budget', \count($runMed) <= (int) ceil(1.4 * 27.0), 'notes=' . \count($runMed));

// (b) A snare landing between two kicks must NOT eat the second kick: the
// full refractory is per instrument, cross-instrument only needs half.
$holeLow = [];
$holeMid = [];
for ($t = 4.0; $t <= 20.0; $t += 2.0) {
    $holeLow[] = [round($t, 3), 0.6];        // kick (not "strong-run" strong)
    $holeMid[] = [round($t + 0.2, 3), 0.6];  // snare 0.2 s later
    $holeLow[] = [round($t + 0.4, 3), 0.6];  // kick 0.2 s after the snare
}
$holeGrid = ['v' => 4, 'bpm' => null, 'beats' => [], 'bands' => ['low' => $holeLow, 'mid' => $holeMid, 'high' => []]];
$holeMed = $gen->generateFromBeats(13, 30, 'medium', $holeGrid);
$holeOk = true;
$holeDetail = '';
foreach ([4.0, 10.0, 18.0] as $t) {
    // 0.05 s tolerance: bass-only slots carry the -30 ms latency compensation.
    foreach ([$t, $t + 0.2, $t + 0.4] as $expected) {
        if (\count(timesAt($holeMed, $expected, 0.05)) !== 1) {
            $holeOk = false;
            $holeDetail = "missing @{$expected}s";
        }
    }
}
check('medium: kick-snare-kick 0.2 s apart -> all 3 taps (no cross-band hole)', $holeOk, $holeDetail);

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
