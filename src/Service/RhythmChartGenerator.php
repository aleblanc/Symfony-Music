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
     * Beat-synced chart (Phase 2): places notes on the TRACKED BEAT GRID
     * (`beats`, fitted client-side by rhythm-analyze.js), subdivided per
     * difficulty tier, keeping only the slots where a real bass/drum attack
     * exists nearby — so quiet sections stay empty and every note lands on the
     * musical pulse. Raw onsets alone are NOT used as note times: on loud mixes
     * they are dense quasi-noise (playtest 2026-08-21), notes must snap to the
     * beat. Bass-backed slots go to the left lanes, drum-backed to the right.
     * Deterministic (RNG seeded from songId + difficulty) so replays are
     * identical. Falls back to {@see self::generate()} when the grid is absent
     * or unusable (old format without `beats`, or too few beats).
     *
     * Note times are RAW audio timestamps: the game loop judges notes against
     * audioPlayback.currentTime (a +2.0 lead-in shift desyncs everything).
     * Ramp-up comes from skipping slots below 2.5 s.
     *
     * @param array{bpm?: float|int|null, beats?: list<float|int>, bass?: list<float|int>, drums?: list<float|int>} $grid
     *
     * @return list<array<string, mixed>>
     */
    public function generateFromBeats(int $songId, int $duration, string $difficulty, array $grid, float $densityMultiplier = 1.0): array
    {
        $totalTime = $duration ?: 180;

        $beats = [];
        foreach (($grid['beats'] ?? []) as $t) {
            $t = (float) $t;
            if ($t > 0.0 && $t <= $totalTime) {
                $beats[] = $t;
            }
        }
        if (\count($beats) < 8) {
            // Absent/old-format/unreliable grid: Phase 1 pseudo-random.
            return $this->generate($songId, $duration, $difficulty, $densityMultiplier);
        }
        sort($beats);

        $bass = array_values(array_map('floatval', $grid['bass'] ?? []));
        $drums = array_values(array_map('floatval', $grid['drums'] ?? []));
        sort($bass);
        sort($drums);
        // If the analyser sent no per-band onsets at all, place on every slot.
        $gateByOnsets = ($bass !== [] || $drums !== []);

        // Deterministic xorshift RNG for lane/mechanic choices.
        $seedString = $songId . '_' . $difficulty . '_beats';
        $seed = crc32($seedString) + hexdec(substr(md5($seedString), 0, 8));
        $rng = static function () use (&$seed): float {
            $seed ^= $seed << 13;
            $seed ^= $seed >> 17;
            $seed ^= $seed << 5;

            return abs($seed % 1000000) / 1000000;
        };

        $lanesCount = ($difficulty === 'easy' || $difficulty === 'medium') ? 4 : ($difficulty === 'demon' ? 10 : 6);

        // Rhythmic subdivision of the beat grid per tier. The legacy per-tier
        // spacing then acts as a density cap (e.g. easy's 0.85 s min gap will
        // naturally keep only every other beat at fast tempos) — greedy
        // skipping stays ON the grid, so the musical phase is preserved.
        $fractions = match ($difficulty) {
            'easy', 'medium' => [0.0],
            'hard', 'expert', 'master' => [0.0, 0.5],
            default => [0.0, 0.25, 0.5, 0.75], // demon
        };

        $baseSpacing = match ($difficulty) {
            'easy' => 0.850,
            'medium' => 0.500,
            'hard' => 0.320,
            'expert' => 0.200,
            'master' => 0.140,
            'demon' => 0.025,
            default => 0.500,
        };
        $minGap = $baseSpacing / max(0.1, min(10.0, $densityMultiplier));

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
        $restrictHolds = \in_array($difficulty, ['easy', 'medium', 'hard'], true);

        // Is there an onset of this band within ±$tol of $t? (binary search)
        $nearOnset = static function (array $sorted, float $t, float $tol = 0.08): bool {
            $lo = 0;
            $hi = \count($sorted) - 1;
            if ($hi < 0) {
                return false;
            }
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($sorted[$mid] < $t) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $d = abs($sorted[$lo] - $t);
            if ($lo > 0) {
                $d = min($d, abs($sorted[$lo - 1] - $t));
            }

            return $d <= $tol;
        };

        // Lane regions per band: bass -> left half, drums -> right half.
        $half = intdiv($lanesCount, 2);
        $bandLanes = [
            0 => range(0, $half - 1),
            1 => range($half, $lanesCount - 1),
        ];

        $laneBusyUntil = array_fill(0, $lanesCount, 0.0);
        $anyHoldUntil = 0.0;
        $lastPlaced = -1000.0;
        $notes = [];
        $beatCount = \count($beats);

        for ($i = 0; $i < $beatCount; ++$i) {
            $b = $beats[$i];
            $next = $beats[$i + 1] ?? ($b + ($b - ($beats[$i - 1] ?? $b - 0.5)));
            $span = max(0.05, $next - $b);

            foreach ($fractions as $f) {
                $time = $b + $span * $f;
                if ($time < 2.5 || $time > $totalTime - 0.5) {
                    continue; // visual ramp-up at the start, buffer at the end
                }
                if ($time - $lastPlaced < $minGap) {
                    continue; // tier density cap (stays on-grid)
                }
                if ($restrictHolds && $time < $anyHoldUntil) {
                    continue;
                }

                // Which bands actually hit on this slot?
                $hasBass = $nearOnset($bass, $time);
                $hasDrums = $nearOnset($drums, $time);
                if ($gateByOnsets && !$hasBass && !$hasDrums) {
                    continue; // silence / no accent here -> no note
                }
                if (!$gateByOnsets) {
                    $hasBass = true;
                    $hasDrums = true;
                }

                $lastPlaced = $time;

                $noteCount = 1;
                if ($rng() < $chordChance) {
                    $noteCount = 2;
                }
                if ($rng() < $tripleChordChance && $lanesCount === 6) {
                    $noteCount = 3;
                }
                $allowLong = !($restrictHolds && $noteCount > 1);

                // Bands to draw lanes from: both hit -> alternate/spread.
                $bands = [];
                if ($hasBass) {
                    $bands[] = 0;
                }
                if ($hasDrums) {
                    $bands[] = 1;
                }

                $usedLanes = [];
                for ($k = 0; $k < $noteCount; ++$k) {
                    $band = $bands[$k % \count($bands)];
                    $region = $bandLanes[$band];
                    $rc = \count($region);

                    // First free lane in the band's region, scanning from a
                    // deterministic random start (stairs/trills, not stacks).
                    $startIdx = ((int) floor($rng() * $rc)) % $rc;
                    $lane = null;
                    for ($s = 0; $s < $rc; ++$s) {
                        $cand = $region[($startIdx + $s) % $rc];
                        if (!\in_array($cand, $usedLanes, true) && $time >= $laneBusyUntil[$cand]) {
                            $lane = $cand;
                            break;
                        }
                    }
                    if ($lane === null) {
                        continue;
                    }
                    $usedLanes[] = $lane;

                    $isLong = $allowLong && ($rng() < $longChance);
                    $isSwipe = !$isLong && ($rng() < $swipeChance);
                    $hitEndSwipe = $isLong && ($rng() < $swipeChance);

                    $dur = 0.0;
                    if ($isLong) {
                        // Hold for one musical beat (min 0.2 s) so releases land
                        // on the pulse too.
                        $dur = max(0.2, $span);
                    }

                    $laneBusyUntil[$lane] = $time + $dur + 0.05;
                    if ($isLong) {
                        $anyHoldUntil = max($anyHoldUntil, $time + $dur);
                    }

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
        }

        if ($notes === []) {
            return $this->generate($songId, $duration, $difficulty, $densityMultiplier);
        }

        return $notes;
    }
}
