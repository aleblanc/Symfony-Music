<?php

namespace App\Service;

/**
 * Server-side rhythm-game anti-cheat. Direct port of the 3-layer validation
 * in the legacy `save_rhythm_score` handler. Pure logic: given a submitted
 * run it returns a ban reason string, or null when the run looks human.
 */
final class RhythmAntiCheat
{
    /**
     * @param list<float|int|string> $hitDeltas per-hit timing errors in ms
     *
     * @return string|null ban reason, or null if the run is accepted
     */
    public function inspect(array $hitDeltas, int $claimedScore, int $maxCombo): ?string
    {
        $totalHits = \count($hitDeltas);

        if ($totalHits > 20) {
            // Layer 1: inhuman precision (>80% of hits under 5ms).
            $suspiciousHits = 0;
            $sum = 0.0;
            foreach ($hitDeltas as $delta) {
                $d = (float) $delta;
                if ($d < 5.0) {
                    ++$suspiciousHits;
                }
                $sum += $d;
            }
            if (($suspiciousHits / $totalHits) > 0.8) {
                return 'Inhuman Precision (80%+ hits under 5ms)';
            }

            // Layer 2: macro / zero-variance detection (std dev < 1.5ms).
            $mean = $sum / $totalHits;
            $varianceSum = 0.0;
            foreach ($hitDeltas as $delta) {
                $varianceSum += ((float) $delta - $mean) ** 2;
            }
            $stdDev = sqrt($varianceSum / $totalHits);
            if ($stdDev < 1.5) {
                return 'Macro Pattern Detected (StdDev < 1.5ms)';
            }
        }

        // Layer 3: mathematical bounds (score/combo spoofing).
        $theoreticalMax = $totalHits * 1000;
        if ($claimedScore > $theoreticalMax || $maxCombo > $totalHits) {
            return 'Data Spoofing (Score or Combo out of mathematical bounds)';
        }

        return null;
    }
}
