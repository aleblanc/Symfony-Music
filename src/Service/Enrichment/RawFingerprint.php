<?php

namespace App\Service\Enrichment;

/**
 * Compares two raw Chromaprint fingerprints (`fpcalc -raw`: one 32-bit word
 * every ~0.124 s) bit by bit at the best time offset. Same recording, any
 * codec or bitrate → ~85-100 % of identical bits; unrelated audio → ~50 %.
 */
final class RawFingerprint
{
    /** Seconds of audio per raw fingerprint item. */
    public const ITEM_SECONDS = 0.1238;

    /**
     * @param list<int> $a
     * @param list<int> $b
     *
     * @return array{score: float, offset: float} share of identical bits (0-1), offset of B relative to A in seconds
     */
    public static function compare(array $a, array $b, int $maxShiftSeconds = 15): array
    {
        $maxShift = (int) round($maxShiftSeconds / self::ITEM_SECONDS);
        $best = ['score' => 0.0, 'shift' => 0];
        for ($shift = -$maxShift; $shift <= $maxShift; ++$shift) {
            $score = self::similarity($a, $b, $shift);
            if ($score !== null && $score > $best['score']) {
                $best = ['score' => $score, 'shift' => $shift];
            }
        }

        return ['score' => $best['score'], 'offset' => round($best['shift'] * self::ITEM_SECONDS, 1)];
    }

    /**
     * Share of identical bits where B (shifted by $shift items) overlaps A;
     * null when the overlap is too short to mean anything (< ~20 s).
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function similarity(array $a, array $b, int $shift): ?float
    {
        $start = max(0, $shift);
        $end = min(\count($a), \count($b) + $shift);
        if ($end - $start < 160) {
            return null;
        }
        $diffBits = 0;
        for ($i = $start; $i < $end; ++$i) {
            $diffBits += self::popcount(($a[$i] ^ $b[$i - $shift]) & 0xFFFFFFFF);
        }

        return 1 - $diffBits / (32 * ($end - $start));
    }

    private static function popcount(int $x): int
    {
        $x -= ($x >> 1) & 0x55555555;
        $x = ($x & 0x33333333) + (($x >> 2) & 0x33333333);
        $x = ($x + ($x >> 4)) & 0x0F0F0F0F;

        return (($x * 0x01010101) & 0xFFFFFFFF) >> 24;
    }
}
