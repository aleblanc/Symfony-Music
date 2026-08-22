<?php

namespace App\Service;

/**
 * Deterministic pseudo-random rhythm chart generator. Direct verbatim port of
 * the legacy `generatePhpChart()` / `calculatePhpLevel()` (see
 * docs/rhythm-game-reference/chart-generation-functions.php). Pure logic, no
 * I/O: given the same (songId, duration, difficulty, density) it always
 * returns the same notes/level (xorshift RNG seeded from the inputs). It does
 * NOT analyse audio — the only audio-derived input is `duration`. Real
 * beat/onset syncing is Phase 2. Arithmetic is intentionally identical to the
 * legacy; explicit int casts replace PHP's implicit `%` coercion so output
 * stays byte-for-byte identical.
 */
final class RhythmChartGenerator
{
    /**
     * @return list<array{time: float, lane: int, isLong?: bool, endTime?: float, isSwipe?: bool, hitEndSwipe?: bool}>
     */
    public function generate(int $songId, int $duration, string $difficulty, float $densityMultiplier = 1.0): array
    {
        // 1. Deterministic seed from song id + difficulty + duration.
        $seedString = $songId . '_' . $difficulty . '_' . $duration;
        $seed = crc32($seedString) + hexdec(substr(md5($seedString), 0, 8));

        // 2. Xorshift RNG (float 0.0..1.0).
        $rng = static function () use (&$seed): float {
            $seed ^= $seed << 13;
            $seed ^= $seed >> 17;
            $seed ^= $seed << 5;

            return abs($seed % 1000000) / 1000000;
        };

        $lanesCount = ($difficulty === 'easy' || $difficulty === 'medium') ? 4 : ($difficulty === 'demon' ? 10 : 6);

        // 3. Base spacing per difficulty tier.
        $baseSpacing = 0.500;
        if ($difficulty === 'easy') {
            $baseSpacing = 0.850;
        } elseif ($difficulty === 'medium') {
            $baseSpacing = 0.500;
        } elseif ($difficulty === 'hard') {
            $baseSpacing = 0.320;
        } elseif ($difficulty === 'expert') {
            $baseSpacing = 0.200;
        } elseif ($difficulty === 'master') {
            $baseSpacing = 0.140;
        } elseif ($difficulty === 'demon') {
            $baseSpacing = 0.025;
        }

        $spacing = $baseSpacing / max(0.1, min(10.0, $densityMultiplier));

        $notes = [];
        $totalTime = $duration ?: 180;

        // 4. Mechanic probabilities per difficulty tier.
        $chordChance = 0.0;
        $tripleChordChance = 0.0;
        if ($difficulty === 'hard') {
            $chordChance = 0.18;
        } elseif ($difficulty === 'expert') {
            $chordChance = 0.35;
            $tripleChordChance = 0.05;
        } elseif ($difficulty === 'master') {
            $chordChance = 0.55;
            $tripleChordChance = 0.15;
        } elseif ($difficulty === 'demon') {
            $chordChance = 0.75;
            $tripleChordChance = 0.30;
        }

        $swipeChance = ($difficulty === 'easy') ? 0.0 : (($difficulty === 'medium') ? 0.08 : 0.25);
        $longChance = ($difficulty === 'demon') ? 0.10 : 0.20;

        $laneBusyUntil = array_fill(0, $lanesCount, 0.0);
        $restrictHolds = \in_array($difficulty, ['easy', 'medium', 'hard'], true);
        $anyHoldUntil = 0.0;

        $isDrop = false;
        $currentStep = $spacing;

        // 5. Generation loop with simulated musical phrasing.
        for ($t = 1.0; $t < $totalTime - 2.0; $t += $currentStep) {
            // Simulate drops (coin-flip every ~20s) — NOT real chorus detection.
            if (((int) $t) % 20 < 1) {
                $isDrop = ($rng() > 0.5);
            }
            $currentStep = $isDrop ? ($spacing * 0.8) : $spacing;

            if ($difficulty === 'demon' || $difficulty === 'master') {
                if ($rng() > 0.85) {
                    $currentStep *= 0.5; // Double-time burst.
                }
                if ($rng() > 0.96) {
                    $currentStep *= 1.5; // Rest / syncopation break.
                }
            }

            if ($restrictHolds && $t < $anyHoldUntil) {
                continue;
            }

            $noteCount = 1;
            if ($rng() < $chordChance) {
                $noteCount = 2;
            }
            if ($rng() < $tripleChordChance && $lanesCount === 6) {
                $noteCount = 3;
            }

            $allowLong = !($restrictHolds && $noteCount > 1);
            $usedLanes = [];

            for ($i = 0; $i < $noteCount; ++$i) {
                $lane = ((int) floor($rng() * $lanesCount)) % $lanesCount;
                $attempts = 0;

                // Semi-random lane searching to create stairs/trills instead of vertical stacks.
                while ((\in_array($lane, $usedLanes, true) || $t < $laneBusyUntil[$lane]) && $attempts < $lanesCount * 2) {
                    $lane = ($lane + ($rng() > 0.5 ? 1 : -1) + $lanesCount) % $lanesCount;
                    ++$attempts;
                }
                if ($attempts >= $lanesCount * 2) {
                    continue;
                }

                $usedLanes[] = $lane;

                $isLong = $allowLong && ($rng() < $longChance);
                $isSwipe = !$isLong && ($rng() < $swipeChance);
                $hitEndSwipe = $isLong && ($rng() < $swipeChance);

                $dur = 0.0;
                if ($isLong) {
                    $durBase = 0.2 + ($rng() * 0.6);
                    $dur = round($durBase / $spacing) * $spacing;
                    if ($dur < 0.2) {
                        $dur = 0.2;
                    }
                }

                $laneBusyUntil[$lane] = $t + $dur + 0.05; // 50ms anti-overlap buffer.
                if ($isLong) {
                    $anyHoldUntil = max($anyHoldUntil, $t + $dur);
                }

                $note = [
                    'time' => round($t + 2.0, 3),
                    'lane' => (int) $lane,
                ];
                if ($isLong) {
                    $note['isLong'] = true;
                    $note['endTime'] = round($t + 2.0 + $dur, 3);
                    if ($hitEndSwipe) {
                        $note['hitEndSwipe'] = true;
                    }
                } elseif ($isSwipe) {
                    $note['isSwipe'] = true;
                }
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * @param list<array<string, mixed>> $notes
     */
    public function level(array $notes, int $duration, string $difficulty = 'medium', float $densityMultiplier = 1.0): int
    {
        if ($duration <= 0 || $notes === []) {
            return 1;
        }

        $totalNotes = \count($notes);
        $nps = ($totalNotes / $duration) / max(0.1, min(10.0, $densityMultiplier));

        // 1. Peak NPS over a rolling 5s window.
        $peakNps = 0.0;
        $windowSize = 5.0;
        for ($i = 0; $i < $duration; $i += 1.0) {
            $windowNotes = 0;
            foreach ($notes as $n) {
                if ($n['time'] >= $i && $n['time'] < $i + $windowSize) {
                    ++$windowNotes;
                }
                if ($n['time'] >= $i + $windowSize) {
                    break; // Optimization exit.
                }
            }
            $currentWindowNps = $windowNotes / $windowSize;
            if ($currentWindowNps > $peakNps) {
                $peakNps = $currentWindowNps;
            }
        }

        // 2. Pattern complexity (lane variance, chords, rhythm irregularity).
        $chords = 0;
        $swipes = 0;
        $holds = 0;
        $crossLaneDistance = 0;
        $lastLane = 1;
        $lastTime = 0.0;
        $timeVarianceSum = 0.0;
        $intervalCount = 0;

        foreach ($notes as $n) {
            if (!empty($n['isLong'])) {
                ++$holds;
            }
            if (!empty($n['isSwipe'])) {
                ++$swipes;
            }

            // Detect simultaneous chords (time diff < 0.05s).
            if (($n['time'] - $lastTime) < 0.05) {
                ++$chords;
            } else {
                if ($intervalCount > 0 && $nps > 0) {
                    $timeVarianceSum += ($n['time'] - $lastTime - (1 / $nps)) ** 2;
                }
                ++$intervalCount;
            }

            $crossLaneDistance += abs($n['lane'] - $lastLane);
            $lastLane = $n['lane'];
            $lastTime = $n['time'];
        }

        // $notes is non-empty here (guarded above), so $totalNotes is >= 1.
        $chordRatio = $chords / $totalNotes;
        $swipeRatio = $swipes / $totalNotes;
        $holdRatio = $holds / $totalNotes;
        $avgJump = $crossLaneDistance / $totalNotes;
        $rhythmIrregularity = $intervalCount > 0 ? sqrt($timeVarianceSum / $intervalCount) : 0;

        // 3. Base polynomial score.
        $baseComplexity = ($nps * 1.4) + ($peakNps * 0.9) + ($avgJump * 3.0) + ($rhythmIrregularity * 12);
        $mechanicWeight = ($chordRatio * 18) + ($swipeRatio * 22) + ($holdRatio * 5);
        $rawScore = $baseComplexity + $mechanicWeight;

        // 4. Bracket + tier multipliers.
        $level = 1.0;
        switch ($difficulty) {
            case 'easy':
                $level = ($rawScore * 0.15) + 1;
                $level = max(1, min(5, $level));
                break;
            case 'medium':
                $level = ($rawScore * 0.35) + 4;
                $level = max(6, min(12, $level));
                break;
            case 'hard':
                $level = ($rawScore * 0.65) + 10;
                $level = max(13, min(24, $level));
                break;
            case 'expert':
                $level = ($rawScore * 1.1) + 22;
                $level = max(25, min(45, $level));
                break;
            case 'master':
                $level = ($rawScore * 1.8) + 40;
                $level = max(46, min(75, $level));
                break;
            case 'demon':
                // 20x multiplier vs baseline to represent a massive but logical leap.
                $level = ($rawScore * 20.0) + 80;
                // Deterministic chaos from absolute note count for uniqueness.
                $level += ($totalNotes % 50) + ($duration % 10);
                $level = max(76, min(999, $level));
                break;
            default:
                $level = 1;
        }

        // 5. Deterministic micro-variance so identical stats still differ.
        $variance = (crc32($totalNotes . $duration) % 5) - 2;
        $finalLevel = round($level) + $variance;

        return max(1, (int) $finalLevel);
    }

    /**
     * Beat-synced chart (Phase 2 v3, "onset-first"): notes are anchored on the
     * ACTUAL bass/drum attacks detected client-side (rhythm-analyze.js), each
     * carrying a normalised strength. Difficulty selects notes by STRENGTH
     * threshold — easy keeps only the big hits, so "3 big drum hits in a row"
     * always become 3 taps (a strong-hit exception bypasses the playability
     * refractory gap). The tracked beat grid, when present, only de-jitters
     * times by snapping onsets to the nearest subdivision (≤ 55 ms); off-grid
     * (syncopated) hits keep their raw time instead of being dropped, which
     * was the main flaw of the previous grid-first version.
     *
     * Chords are musical, not random: simultaneous attacks in different bands
     * (kick+snare within 30 ms), and on hard/expert the partner hit must be
     * strong itself. Holds only on bass hits followed by a long gap
     * (sustained low end), so they never eat the next tap. Lanes are FIXED
     * per instrument (Taiko-style, user spec): bass always on one lane,
     * snare on another, hats on a third — the chart reads as the
     * instruments. Density is budgeted in notes/second per tier (the
     * strength cut adapts to each song's mix instead of a fixed threshold).
     * Deterministic (xorshift seeded from songId + difficulty). Falls back to
     * {@see self::generate()} when the grid is absent or too sparse.
     *
     * Note times are RAW audio timestamps: the game loop judges notes against
     * audioPlayback.currentTime. Ramp-up comes from skipping slots below 2.5 s.
     *
     * Accepts both the v3 grid ({v:3, beats, bands:{low|mid|high: [[t,s]…]}})
     * and the legacy v2 one ({beats, bass:[t…], drums:[t…]}, strengths
     * defaulted) — v2 grids are re-analysed client-side anyway (beatAware).
     *
     * @param array<string, mixed> $grid
     *
     * @return list<array<string, mixed>>
     */
    public function generateFromBeats(int $songId, int $duration, string $difficulty, array $grid, float $densityMultiplier = 1.0): array
    {
        $totalTime = $duration ?: 180;
        $density = max(0.1, min(10.0, $densityMultiplier));

        // ---- 1. Normalise input events to [t, strength, band]. -------------
        $events = [];
        $bands = \is_array($grid['bands'] ?? null) ? $grid['bands'] : null;
        if ($bands !== null) {
            foreach (['low' => 0, 'mid' => 1, 'high' => 2] as $key => $bandNo) {
                foreach (\is_array($bands[$key] ?? null) ? $bands[$key] : [] as $pair) {
                    if (\is_array($pair) && isset($pair[0]) && is_numeric($pair[0])) {
                        $t = (float) $pair[0];
                        if ($t > 0.0 && $t <= $totalTime) {
                            $s = isset($pair[1]) && is_numeric($pair[1]) ? max(0.0, min(1.0, (float) $pair[1])) : 0.6;
                            $events[] = ['t' => $t, 's' => $s, 'band' => $bandNo];
                        }
                    }
                }
            }
        } else {
            // Legacy v2: times only. bass -> low; drums (HP 2 kHz, hats-ish) -> high.
            foreach (['bass' => 0, 'drums' => 2] as $key => $bandNo) {
                foreach (\is_array($grid[$key] ?? null) ? $grid[$key] : [] as $t) {
                    if (is_numeric($t) && (float) $t > 0.0 && (float) $t <= $totalTime) {
                        $events[] = ['t' => (float) $t, 's' => 0.6, 'band' => $bandNo];
                    }
                }
            }
        }
        if (\count($events) < 8) {
            return $this->generate($songId, $duration, $difficulty, $densityMultiplier);
        }
        usort($events, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        // ---- 2. Tier parameters. -------------------------------------------
        $lanesCount = ($difficulty === 'easy' || $difficulty === 'medium') ? 4 : ($difficulty === 'demon' ? 10 : 6);

        // Target chart density in notes/second (playtest 2026-08-22: fixed
        // strength thresholds made hard unplayable on compressed mixes — the
        // strength distribution varies wildly per master, so density is now
        // budgeted explicitly and the threshold adapts to each song).
        $targetNps = match ($difficulty) {
            'easy' => 0.8,
            'medium' => 1.4,
            'hard' => 2.2,
            'expert' => 3.2,
            'master' => 4.4,
            default => 6.0, // demon
        } * $density;

        // Absolute strength floor: below this a hit is never a note on the
        // tier, no matter how sparse the song (easy stays "big hits only").
        $strengthFloor = match ($difficulty) {
            'easy' => 0.45,
            'medium' => 0.30,
            'hard' => 0.18,
            'expert' => 0.10,
            'master' => 0.05,
            default => 0.0, // demon: everything
        };

        // Playability refractory (NOT a musical grid): small enough that real
        // consecutive drum hits at song tempos are all kept.
        $refractory = max(0.03, match ($difficulty) {
            'easy' => 0.40,
            'medium' => 0.30,
            'hard' => 0.22,
            'expert' => 0.15,
            'master' => 0.11,
            default => 0.06, // demon
        } / $density);

        // Chords stay rare on the mid tiers: the second band must itself be a
        // strong hit (kick AND snare both accented), not incidental overlap.
        $chordSecondMin = match ($difficulty) {
            'hard' => 0.5,
            'expert' => 0.35,
            default => 0.0,
        };

        $allowedBands = match ($difficulty) {
            'easy', 'medium', 'hard' => [0, 1],
            default => [0, 1, 2],
        };
        // On expert the hats band only contributes its accents.
        $highBandMin = $difficulty === 'expert' ? 0.5 : 0.0;

        $maxChord = match ($difficulty) {
            'easy', 'medium' => 1,
            'hard', 'expert' => 2,
            default => 3, // master, demon
        };

        $swipeChance = ($difficulty === 'easy') ? 0.0 : (($difficulty === 'medium') ? 0.08 : 0.25);
        $holdChance = ($difficulty === 'demon') ? 0.15 : 0.35;

        // ---- 3. Optional de-jitter: snap onsets to the beat subdivision. ----
        $subdivisions = [];
        $beats = [];
        foreach (\is_array($grid['beats'] ?? null) ? $grid['beats'] : [] as $t) {
            if (is_numeric($t) && (float) $t > 0.0) {
                $beats[] = (float) $t;
            }
        }
        if (\count($beats) >= 8) {
            sort($beats);
            $parts = match ($difficulty) {
                'easy', 'medium' => 2,
                'hard', 'expert' => 4,
                default => 8,
            };
            $n = \count($beats);
            for ($i = 0; $i < $n; ++$i) {
                $b = $beats[$i];
                $next = $beats[$i + 1] ?? ($b + ($b - ($beats[$i - 1] ?? $b - 0.5)));
                $span = max(0.05, $next - $b);
                for ($p = 0; $p < $parts; ++$p) {
                    $subdivisions[] = $b + $span * ($p / $parts);
                }
            }
        }
        $snap = static function (float $t, float $tol) use ($subdivisions): float {
            $count = \count($subdivisions);
            if ($count === 0) {
                return $t;
            }
            $lo = 0;
            $hi = $count - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($subdivisions[$mid] < $t) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $best = $subdivisions[$lo];
            if ($lo > 0 && abs($subdivisions[$lo - 1] - $t) < abs($best - $t)) {
                $best = $subdivisions[$lo - 1];
            }

            return abs($best - $t) <= $tol ? $best : $t;
        };

        // ---- 4a. Tier filter, then adaptive density budget. -----------------
        $candidates = [];
        foreach ($events as $e) {
            if (!\in_array($e['band'], $allowedBands, true)) {
                continue;
            }
            if ($e['s'] < $strengthFloor || ($e['band'] === 2 && $e['s'] < $highBandMin)) {
                continue;
            }
            $candidates[] = $e;
        }

        // Keep only ~`targetNps * duration` hits — but like a MUSICIAN, never
        // by punching random holes in an even pattern (playtest 2026-08-22:
        // "des trous dans la batterie/basse"). Candidates are grouped into
        // RUNS (same instrument, quasi-constant spacing); the budget keeps
        // whole runs, and when a run does not fit it is thinned EVENLY
        // (every 2nd/3rd note = the same groove at a simpler subdivision),
        // instead of dropping the weakest hits one by one.
        $window = max(10.0, $totalTime - 3.0);
        $budget = (int) ceil($targetNps * $window);
        if (\count($candidates) > $budget) {
            $byBand = [];
            foreach ($candidates as $i => $e) {
                $byBand[$e['band']][] = $i;
            }
            /** @var list<list<int>> $units run = list of candidate indices */
            $units = [];
            foreach ($byBand as $indices) {
                $n = \count($indices);
                $run = [$indices[0]];
                $prevIoi = 0.0;
                for ($j = 1; $j <= $n; ++$j) {
                    if ($j < $n) {
                        $ioi = $candidates[$indices[$j]]['t'] - $candidates[$indices[$j - 1]]['t'];
                        $sameSpacing = \count($run) === 1 || abs($ioi - $prevIoi) <= max(0.035, 0.2 * $prevIoi);
                        if ($ioi > 0.0 && $ioi <= 0.9 && $sameSpacing) {
                            $run[] = $indices[$j];
                            $prevIoi = $ioi;
                            continue;
                        }
                    }
                    // Close the run: 3+ evenly spaced hits form a pattern
                    // that must survive (or thin) as a whole; shorter runs
                    // are independent singletons.
                    if (\count($run) >= 3) {
                        $units[] = $run;
                    } else {
                        foreach ($run as $idx) {
                            $units[] = [$idx];
                        }
                    }
                    if ($j < $n) {
                        $run = [$indices[$j]];
                        $prevIoi = 0.0;
                    }
                }
            }

            // Strongest units first; equal-strength ties broken by a hash of
            // the unit's first index so drops spread uniformly across the
            // song, not from one end.
            $unitScore = [];
            foreach ($units as $u => $idxList) {
                $sum = 0.0;
                foreach ($idxList as $idx) {
                    $sum += $candidates[$idx]['s'];
                }
                $unitScore[$u] = $sum / \count($idxList);
            }
            $unitOrder = array_keys($units);
            usort($unitOrder, static function (int $a, int $b) use ($unitScore, $units): int {
                return ($unitScore[$b] <=> $unitScore[$a])
                    ?: ((($units[$a][0] * 2654435761) % 4294967296) <=> (($units[$b][0] * 2654435761) % 4294967296));
            });

            $keepIdx = [];
            $remaining = $budget;
            foreach ($unitOrder as $u) {
                if ($remaining <= 0) {
                    break;
                }
                $idxList = $units[$u];
                $m = \count($idxList);
                if ($m <= $remaining) {
                    foreach ($idxList as $idx) {
                        $keepIdx[$idx] = true;
                    }
                    $remaining -= $m;
                    continue;
                }
                // Even thinning: keep every k-th hit starting from the run's
                // first (usually the downbeat) — uniform spacing, no holes.
                $k = (int) ceil($m / $remaining);
                for ($p = 0; $p < $m; $p += $k) {
                    $keepIdx[$idxList[$p]] = true;
                    --$remaining;
                }
            }
            $filtered = [];
            foreach ($candidates as $i => $e) {
                if (isset($keepIdx[$i])) {
                    $filtered[] = $e;
                }
            }
            $candidates = $filtered;
        }

        // ---- 4b. Group near-simultaneous events into slots (chord candidates).
        // "Kick-lock" (playtest 2026-08-22: bass felt sloppier than drums):
        // drums are the timing reference. When a bass attack co-occurs with a
        // drum hit (≤45 ms) the slot adopts the DRUM's timestamp — bass onsets
        // smear later than stick hits, drummers don't.
        /** @var list<array{t: float, s: float, bands: array<int, float>, midT: float|null, midS: float}> $slots */
        $slots = [];
        foreach ($candidates as $e) {
            $isMid = $e['band'] === 1;
            $last = \count($slots) - 1;
            if ($last >= 0 && ($e['t'] - $slots[$last]['t']) <= 0.045) {
                // Same musical instant: merge (kick+snare together = chord).
                $prev = $slots[$last];
                $prevBands = $prev['bands'];
                $prevBands[$e['band']] = max($prevBands[$e['band']] ?? 0.0, $e['s']);
                $midT = $prev['midT'];
                $midS = $prev['midS'];
                if ($isMid && $e['s'] > $midS) {
                    $midT = $e['t'];
                    $midS = $e['s'];
                }
                $slots[$last] = ['t' => $prev['t'], 's' => max($prev['s'], $e['s']), 'bands' => $prevBands, 'midT' => $midT, 'midS' => $midS];
                continue;
            }
            $slots[] = [
                't' => $e['t'],
                's' => $e['s'],
                'bands' => [$e['band'] => $e['s']],
                'midT' => $isMid ? $e['t'] : null,
                'midS' => $isMid ? $e['s'] : 0.0,
            ];
        }

        // Adopt the drum timestamp where available, then de-jitter onto the
        // beat grid. Bass-only slots first get a fixed latency compensation
        // (-30 ms: a bass attack always crosses the flux detector LATE, unlike
        // stick hits) and a wider snap window (0.08 s vs 0.055 s), so a hit
        // meant on the beat is pulled back onto it instead of drifting to the
        // next subdivision.
        foreach ($slots as $i => $slot) {
            $bassOnly = $slot['midT'] === null && !isset($slot['bands'][2]);
            $anchor = $slot['midT'] ?? ($bassOnly ? max(0.0, $slot['t'] - 0.03) : $slot['t']);
            $slots[$i]['t'] = $snap($anchor, $bassOnly ? 0.08 : 0.055);
        }
        usort($slots, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        // ---- 5. Refractory pass, PER INSTRUMENT. -----------------------------
        // The full comfort gap applies within one instrument; across
        // instruments only half is required (opposite hands on the zoned
        // lanes). Otherwise a snare falling between two kicks silently eats
        // the second kick — a pattern hole (playtest 2026-08-22).
        $globalRefr = max(0.03, $refractory * 0.5);
        /** @var list<array{t: float, s: float, bands: array<int, float>, midT: float|null, midS: float}> $kept */
        $kept = [];
        $lastKept = -1000.0;
        $lastKeptBand = [0 => -1000.0, 1 => -1000.0, 2 => -1000.0];
        foreach ($slots as $slot) {
            if ($slot['t'] < 2.5 || $slot['t'] > $totalTime - 0.5) {
                continue; // visual ramp-up at the start, buffer at the end
            }
            $gap = $slot['t'] - $lastKept;
            $bandGap = 1000.0;
            foreach ($slot['bands'] as $b => $unused) {
                $bandGap = min($bandGap, $slot['t'] - $lastKeptBand[$b]);
            }
            // Big hits ("gros coups") may pack tighter than the tier's comfort
            // gap — 3 strong hits in a row must always yield 3 taps.
            $strongRun = $slot['s'] >= 0.7 && $bandGap >= max(0.20, $refractory * 0.6);
            if (($bandGap < $refractory && !$strongRun) || $gap < $globalRefr) {
                continue;
            }
            $kept[] = $slot;
            $lastKept = $slot['t'];
            foreach ($slot['bands'] as $b => $unused) {
                $lastKeptBand[$b] = $slot['t'];
            }
        }
        if (\count($kept) < 8) {
            return $this->generate($songId, $duration, $difficulty, $densityMultiplier);
        }

        // ---- 6. Place notes: lanes per band region, musical chords/holds. ---
        $seedString = $songId . '_' . $difficulty . '_beats';
        $seed = crc32($seedString) + hexdec(substr(md5($seedString), 0, 8));
        $rng = static function () use (&$seed): float {
            $seed ^= $seed << 13;
            $seed ^= $seed >> 17;
            $seed ^= $seed << 5;

            return abs($seed % 1000000) / 1000000;
        };

        // Instrument ZONES (user spec 2026-08-22, refined after playtest:
        // a single fixed lane per instrument left half the keys unused).
        // Each instrument owns a group of adjacent lanes — bass on the left,
        // drums next, hats on the right — and consecutive hits of the same
        // instrument ALTERNATE inside their zone. The chart still reads as
        // the instruments (left hand = bass, right hand = drums) but every
        // key gets used, and fast runs become easy left/right trills instead
        // of one-finger jacks.
        // The lane split adapts to the instruments actually present in THIS
        // chart: a tier or song without hats spreads bass/drums over all the
        // keys instead of leaving dead lanes (hard only allows low+mid, so
        // its 6 keys split 3+3).
        $present = [];
        foreach ($kept as $slot) {
            foreach ($slot['bands'] as $b => $unused) {
                $present[$b] = true;
            }
        }
        // On 4 keys the hats share the drum zone: no room for a third zone.
        $zoneOf = [0 => 0, 1 => 1, 2 => ($lanesCount === 4 ? 1 : 2)];
        $zoneIdSet = [];
        foreach (array_keys($present) as $b) {
            $zoneIdSet[$zoneOf[$b]] = true;
        }
        $zoneIds = array_keys($zoneIdSet);
        sort($zoneIds);
        $zoneCountReal = max(1, \count($zoneIds));
        // Fallback: any zone not present in the chart spans the whole
        // keyboard (never hit by construction, keeps the lookup total).
        $allLanes = range(0, $lanesCount - 1);
        $zones = [0 => $allLanes, 1 => $allLanes, 2 => $allLanes];
        foreach ($zoneIds as $idx => $zid) {
            $start = (int) round($idx * $lanesCount / $zoneCountReal);
            $end = (int) round(($idx + 1) * $lanesCount / $zoneCountReal) - 1;
            $zones[$zid] = range($start, max($start, $end));
        }
        $zoneCursor = [0 => 0, 1 => 0, 2 => 0];

        $laneBusyUntil = array_fill(0, $lanesCount, 0.0);
        $notes = [];
        $keptCount = \count($kept);

        for ($i = 0; $i < $keptCount; ++$i) {
            $slot = $kept[$i];
            $time = $slot['t'];
            $gapNext = ($kept[$i + 1]['t'] ?? $totalTime) - $time;

            // Bands present on this slot, strongest first. Chord partners
            // (2nd/3rd band) must be strong hits themselves on the mid tiers.
            $slotBands = $slot['bands'];
            arsort($slotBands);
            $bandList = [];
            foreach ($slotBands as $band => $bandStrength) {
                if ($bandList === [] || $bandStrength >= $chordSecondMin) {
                    $bandList[] = $band;
                }
            }
            $noteCount = min($maxChord, \count($bandList));

            $usedLanes = [];
            for ($k = 0; $k < $noteCount; ++$k) {
                // Next free lane in the instrument's zone, alternating each
                // hit; skipped entirely if the whole zone is busy (held note
                // or chord partner sharing the zone on 4 lanes).
                $band = $bandList[$k];
                $zid = $zoneOf[$band];
                $zone = $zones[$zid];
                $zoneSize = \count($zone);
                $lane = -1;
                for ($try = 0; $try < $zoneSize; ++$try) {
                    $cand = $zone[($zoneCursor[$zid] + $try) % $zoneSize];
                    if (!\in_array($cand, $usedLanes, true) && $time >= $laneBusyUntil[$cand]) {
                        $lane = $cand;
                        $zoneCursor[$zid] = ($zoneCursor[$zid] + $try + 1) % $zoneSize;
                        break;
                    }
                }
                if ($lane < 0) {
                    continue;
                }
                $usedLanes[] = $lane;

                // Hold: bass attack followed by a long quiet gap = sustained
                // low end. Never on chords, never eats the next tap.
                $isLong = $bandList[$k] === 0 && $noteCount === 1 && $gapNext >= 0.6 && $rng() < $holdChance;
                $isSwipe = !$isLong && ($rng() < $swipeChance);
                $hitEndSwipe = $isLong && ($rng() < $swipeChance);

                $dur = 0.0;
                if ($isLong) {
                    $dur = max(0.25, min($gapNext - 0.2, 2.0));
                }

                $laneBusyUntil[$lane] = $time + $dur + 0.05;

                $note = [
                    'time' => round($time, 3),
                    'lane' => (int) $lane,
                ];
                if ($isLong) {
                    $note['isLong'] = true;
                    $note['endTime'] = round($time + $dur, 3);
                    if ($hitEndSwipe) {
                        $note['hitEndSwipe'] = true;
                    }
                } elseif ($isSwipe) {
                    $note['isSwipe'] = true;
                }
                $notes[] = $note;
            }
        }

        if ($notes === []) {
            return $this->generate($songId, $duration, $difficulty, $densityMultiplier);
        }

        return $notes;
    }
}
