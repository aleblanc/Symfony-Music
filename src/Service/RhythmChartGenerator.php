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
        $restrictHolds = in_array($difficulty, ['easy', 'medium', 'hard'], true);
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
                while ((in_array($lane, $usedLanes, true) || $t < $laneBusyUntil[$lane]) && $attempts < $lanesCount * 2) {
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

        $totalNotes = count($notes);
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

        $chordRatio = $totalNotes > 0 ? ($chords / $totalNotes) : 0;
        $swipeRatio = $totalNotes > 0 ? ($swipes / $totalNotes) : 0;
        $holdRatio = $totalNotes > 0 ? ($holds / $totalNotes) : 0;
        $avgJump = $totalNotes > 0 ? ($crossLaneDistance / $totalNotes) : 0;
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
}
