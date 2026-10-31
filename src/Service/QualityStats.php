<?php

namespace App\Service;

use App\Enum\AudioQuality;
use App\Translation\LabelMessage;

/**
 * Audio quality figures of the library for the stats page: tracks per
 * quality tier, per format, and per bitrate range inside each format, always
 * from the worst to the best. Pure: fed with grouped rows from
 * MusicRepository::qualityRows().
 */
class QualityStats
{
    /**
     * Rough share of listeners who hear no difference with the original (blind
     * ABX tests, age-related hearing loss), as [share => from this]: kb/s per
     * codec family, kHz for the treble cutoff. An order of magnitude, not a
     * measure: trained ears on a revealing passage do better.
     *
     * For the treble cutoff the kHz follow the MP3 kb/s: a cutoff is judged as
     * the MP3 whose encoder cuts there (SpectrumAnalyzer::mp3EquivalentRanges),
     * so 90 % starts with the 192's range and 99 % with the 256's. The ear
     * alone would allow less (most adults hear nothing above 16–17 kHz), but
     * what a low bitrate loses is not only the treble.
     */
    private const TRANSPARENT_KBPS = [
        'opus' => [90 => 96, 99 => 128],
        'efficient' => [90 => 128, 99 => 192],
        'mp3' => [90 => 192, 99 => 256],
    ];

    /** Lower bounds (kb/s) of the bitrate ranges of lossy formats. */
    public const LOSSY_STEPS = [0, 64, 96, 128, 160, 192, 224, 256, 320];

    private const FORMAT_LABELS = [
        'mp3' => 'MP3',
        'mp4' => 'M4A (AAC)',
        'aac' => 'AAC',
        'm4a' => 'M4A',
        'alac' => 'M4A (ALAC)',
        'flac' => 'FLAC',
        'vorbis' => 'Ogg Vorbis',
        'ogg' => 'Ogg',
        'opus' => 'Opus',
        'wav' => 'WAV',
        'aiff' => 'AIFF',
        'asf' => 'WMA',
        'wma' => 'WMA',
    ];

    /**
     * @param list<array{format: ?string, lossless: ?bool, kbps: ?int, sampleRate: ?int, bitsPerSample: ?int, effectiveKbps?: ?int, n: int}> $rows
     *
     * @return array{
     *     total: int,
     *     unscanned: int,
     *     tiers: list<array{key: string, label: LabelMessage, count: int, pct: float}>,
     *     formats: list<array{key: string, label: string, lossless: bool, count: int, pct: float, transparent: ?array<int, int>, buckets: list<array{key: string, label: LabelMessage, quality: ?string, count: int, inflated: int, pct: float, min: ?int, max: ?int, transparent: ?int}>}>,
     *     declared: list<array{key: string, label: LabelMessage, count: int, lower: int, real: list<array{key: string, label: LabelMessage, count: int}>}>
     * }
     *
     * Tier and bucket labels are translatable (`|trans` in Twig) and cast to English;
     * bucket `key` is the stable technical id (the English label).
     */
    public function build(array $rows): array
    {
        $total = 0;
        $unscanned = 0;
        $tiers = array_fill_keys(array_map(static fn(AudioQuality $q) => $q->value, AudioQuality::cases()), 0);
        $unknownTier = 0;
        // Declared tier → real tier (app:cron:spectrum: an inflated file at its real bitrate).
        /** @var array<string, array<string, int>> $declaredToReal */
        $declaredToReal = [];
        /** @var array<string, array{lossless: bool, count: int, buckets: array<string, array{key: string, label: LabelMessage, quality: ?string, count: int, inflated: int, sort: int, min: ?int, max: ?int}>}> $formats */
        $formats = [];

        foreach ($rows as $row) {
            $n = $row['n'];
            $total += $n;
            $format = $row['format'];
            if ($format === null || $format === '') {
                $unscanned += $n;
                continue;
            }

            $declared = AudioQuality::classify($format, $row['lossless'], $row['kbps'] !== null ? $row['kbps'] * 1000 : null);
            // Tiers by real quality (an inflated file counts at its real bitrate); formats and ranges as declared.
            $quality = ($row['effectiveKbps'] ?? null) !== null ? AudioQuality::classify($format, $row['lossless'], null, $row['effectiveKbps']) : $declared;
            if ($quality === null) {
                $unknownTier += $n;
            } else {
                $tiers[$quality->value] += $n;
            }
            if ($declared !== null && $quality !== null) {
                $declaredToReal[$declared->value][$quality->value] = ($declaredToReal[$declared->value][$quality->value] ?? 0) + $n;
            }

            $lossless = $declared === AudioQuality::Lossless;
            $key = $format . ($lossless && $format === 'mp4' ? '-lossless' : '');
            $formats[$key] ??= ['lossless' => $lossless, 'count' => 0, 'buckets' => []];
            $formats[$key]['count'] += $n;

            $bucket = $lossless ? $this->losslessBucket($row['bitsPerSample'], $row['sampleRate']) : $this->lossyBucket($format, $row['kbps']);
            $formats[$key]['buckets'][$bucket['key']] ??= $bucket + ['count' => 0, 'inflated' => 0];
            $formats[$key]['buckets'][$bucket['key']]['count'] += $n;
            // Of which inflated: their real tier is lower than the declared one (same rule as
            // the declared → real table; a cutoff that keeps the tier is not counted).
            if ($quality !== null && $declared !== null && $quality !== $declared) {
                $formats[$key]['buckets'][$bucket['key']]['inflated'] += $n;
            }
        }

        $pct = static fn(int $n, int $of): float => $of > 0 ? round(100 * $n / $of, 1) : 0.0;

        $tierList = [];
        if ($unknownTier > 0) {
            $tierList[] = ['key' => 'unknown', 'label' => new LabelMessage('ui.quality.unknown_bitrate', 'Unknown bitrate'), 'count' => $unknownTier, 'pct' => $pct($unknownTier, $total)];
        }
        foreach (AudioQuality::cases() as $q) {
            $tierList[] = ['key' => $q->value, 'label' => $q->label(), 'count' => $tiers[$q->value], 'pct' => $pct($tiers[$q->value], $total)];
        }

        $formatList = [];
        foreach ($formats as $key => $f) {
            $buckets = array_values($f['buckets']);
            usort($buckets, static fn(array $a, array $b) => $a['sort'] <=> $b['sort']);
            $formatList[] = [
                'key' => (string) $key,
                'label' => $key === 'mp4-lossless' ? 'M4A (ALAC)' : self::formatLabel((string) $key),
                'lossless' => $f['lossless'],
                'count' => $f['count'],
                'pct' => $pct($f['count'], $total),
                // Bitrates from which ≈ 90 / 99 % hear no difference (null: lossless).
                'transparent' => $f['lossless'] ? null : self::transparentKbps((string) $key),
                'buckets' => array_map(static fn(array $b) => [
                    'key' => $b['key'],
                    'label' => $b['label'],
                    'quality' => $b['quality'],
                    'count' => $b['count'],
                    'inflated' => $b['inflated'],
                    'pct' => $pct($b['count'], $f['count']),
                    'min' => $b['min'],
                    'max' => $b['max'],
                    'transparent' => $f['lossless'] ? null : self::transparentShare(self::transparentKbps((string) $key), $b['min']),
                ], $buckets),
            ];
        }
        // Worst formats first: lossy before lossless, then by share of the library.
        usort($formatList, static fn(array $a, array $b) => [$a['lossless'], -$a['count']] <=> [$b['lossless'], -$b['count']]);

        $declaredList = [];
        foreach (AudioQuality::cases() as $q) {
            $real = $declaredToReal[$q->value] ?? [];
            if ($real === []) {
                continue;
            }
            $lower = array_sum($real) - ($real[$q->value] ?? 0);
            $declaredList[] = [
                'key' => $q->value,
                'label' => $q->label(),
                'count' => array_sum($real),
                'lower' => $lower,
                // Worst first, the declared tier itself left out.
                'real' => array_values(array_map(
                    static fn(AudioQuality $r) => ['key' => $r->value, 'label' => $r->label(), 'count' => $real[$r->value]],
                    array_filter(AudioQuality::cases(), static fn(AudioQuality $r) => $r !== $q && isset($real[$r->value])),
                )),
            ];
        }

        return ['total' => $total, 'unscanned' => $unscanned, 'tiers' => $tierList, 'formats' => $formatList, 'declared' => $declaredList];
    }

    /**
     * Tracks per treble cutoff (app:cron:spectrum), one range per MP3 bitrate
     * whose LAME encoder cuts there (SpectrumAnalyzer::mp3EquivalentRanges():
     * 15.5–16.5 kHz is a 112, 16.5–17.3 a 128, ≥ 20.3 a 320), lowest first,
     * each coloured by that bitrate's tier. Only an encoder's wall (a sharp
     * cutoff) means that bitrate: a natural roll-off (old recording, solo
     * piano) is counted apart, in `soft`, and drawn grey. Fed with MusicRepository::cutoffRows().
     *
     * @param list<array{hz: int, sharp: ?bool, n: int}> $rows cutoff in Hz (-2 not analysed yet, -1 not decodable); sharp null when not known yet (analysed before it was recorded: counted with the walls, as it was)
     *
     * @return array{analysed: int, failed: int, pending: int, unknown: int, buckets: list<array{label: LabelMessage, min: ?int, max: ?int, kbps: int, quality: string, count: int, sharp: int, soft: int, pct: float, pctSharp: float, pctSoft: float, transparent: ?int}>, transparent: array<int, float>} min / max: bounds in Hz, included (null: open); kbps: the MP3 cut there; quality: its tier's key; count: sharp + soft; pct*: shares of the analysed tracks; transparent: ≈ share (90, 99) of listeners who hear no difference from the range's MP3 bitrate up, and the kHz each share starts at
     */
    public function spectrum(array $rows): array
    {
        $analysed = 0;
        $failed = 0;
        $pending = 0;
        $unknown = 0;
        $ranges = SpectrumAnalyzer::mp3EquivalentRanges();
        $walls = array_fill(0, \count($ranges), 0);
        $softs = array_fill(0, \count($ranges), 0);
        foreach ($rows as $row) {
            ['hz' => $hz, 'n' => $n] = $row;
            $sharp = $row['sharp'] ?? null;
            if ($hz === -2) {
                $pending += $n;
            } elseif ($hz < 0) {
                $failed += $n;
            } else {
                $analysed += $n;
                if ($sharp === null) {
                    $unknown += $n;
                }
                foreach ($ranges as $i => [, $below]) {
                    if ($below === null || $hz < $below) {
                        if ($sharp === false) {
                            $softs[$i] += $n;
                        } else {
                            $walls[$i] += $n;
                        }
                        break;
                    }
                }
            }
        }

        $pct = static fn(int $n): float => $analysed > 0 ? round(100 * $n / $analysed, 1) : 0.0;
        // The kHz each share starts at: the range of the first MP3 bitrate that reaches it.
        $transparent = [];
        foreach (self::TRANSPARENT_KBPS['mp3'] as $share => $fromKbps) {
            foreach ($ranges as [$from, , $kbps]) {
                if ($kbps >= $fromKbps) {
                    $transparent[$share] = (float) ($from ?? 0) / 1000;
                    break;
                }
            }
        }
        $buckets = [];
        foreach ($ranges as $i => [$from, $below, $kbps]) {
            $min = $from;
            $max = $below !== null ? $below - 1 : null;
            $buckets[] = [
                'label' => self::cutoffLabel($min, $max),
                'min' => $min,
                'max' => $max,
                'kbps' => $kbps,
                'quality' => (AudioQuality::classify('mp3', false, $kbps * 1000) ?? AudioQuality::VeryLow)->value,
                'count' => $walls[$i] + $softs[$i],
                'sharp' => $walls[$i],
                'soft' => $softs[$i],
                'pct' => $pct($walls[$i] + $softs[$i]),
                'pctSharp' => $pct($walls[$i]),
                'pctSoft' => $pct($softs[$i]),
                'transparent' => self::transparentShare(self::TRANSPARENT_KBPS['mp3'], $kbps),
            ];
        }

        return ['analysed' => $analysed, 'failed' => $failed, 'pending' => $pending, 'unknown' => $unknown, 'buckets' => $buckets, 'transparent' => $transparent];
    }

    /**
     * Label of a treble cutoff range, bounds in Hz included (null: open):
     * "< 15.5 kHz", "15.5–16.5 kHz", "≥ 20.3 kHz". The kHz are numbers, ICU
     * formats them per locale (15.5 / 15,5).
     */
    public static function cutoffLabel(?int $minHz, ?int $maxHz): LabelMessage
    {
        $khz = static fn(int $hz): float => $hz / 1000;
        $text = static fn(float $k): string => rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.');

        return match (true) {
            $maxHz === null => new LabelMessage('ui.quality.cutoff.at_least', \sprintf('≥ %s kHz', $text($khz($minHz ?? 0))), ['khz' => $khz($minHz ?? 0)]),
            $minHz === null => new LabelMessage('ui.quality.cutoff.below', \sprintf('< %s kHz', $text($khz($maxHz + 1))), ['khz' => $khz($maxHz + 1)]),
            default => new LabelMessage('ui.quality.cutoff.range', \sprintf('%s–%s kHz', $text($khz($minHz)), $text($khz($maxHz + 1))), ['min' => $khz($minHz), 'max' => $khz($maxHz + 1)]),
        };
    }

    /**
     * Bitrates (kb/s) from which ≈ 90 and 99 % of listeners hear no difference
     * with the original, for a lossy format.
     *
     * @return array<int, int> [90 => kb/s, 99 => kb/s]
     */
    public static function transparentKbps(string $format): array
    {
        return self::TRANSPARENT_KBPS[match (true) {
            $format === 'opus' => 'opus',
            \in_array($format, ['mp4', 'aac', 'm4a', 'vorbis', 'ogg'], true) => 'efficient',
            default => 'mp3',
        }];
    }

    /**
     * The share (90, 99) of listeners who hear no difference from a range
     * starting at $from, null below the 90 % threshold.
     *
     * @param array<int, int> $thresholds [share => from]
     */
    private static function transparentShare(array $thresholds, ?int $from): ?int
    {
        $share = null;
        foreach ($thresholds as $pct => $at) {
            if ($from !== null && $from >= $at) {
                $share = $pct;
            }
        }

        return $share;
    }

    public static function formatLabel(string $format): string
    {
        return self::FORMAT_LABELS[$format] ?? strtoupper($format);
    }

    /**
     * @return array{key: string, label: LabelMessage, quality: ?string, sort: int, min: ?int, max: ?int}
     */
    private function lossyBucket(string $format, ?int $kbps): array
    {
        if ($kbps === null || $kbps <= 0) {
            return ['key' => 'Unknown bitrate', 'label' => new LabelMessage('ui.quality.unknown_bitrate', 'Unknown bitrate'), 'quality' => null, 'sort' => -1, 'min' => null, 'max' => null];
        }
        $steps = self::LOSSY_STEPS;
        $i = \count($steps) - 1;
        while ($steps[$i] > $kbps) {
            --$i;
        }
        $min = $steps[$i];
        $max = isset($steps[$i + 1]) ? $steps[$i + 1] - 1 : null;
        $label = match (true) {
            $min === 0 => new LabelMessage('ui.quality.bucket.below', \sprintf('< %d kb/s', $steps[1]), ['kbps' => $steps[1]]),
            $max === null => new LabelMessage('ui.quality.bucket.at_least', \sprintf('≥ %d kb/s', $min), ['kbps' => $min]),
            default => new LabelMessage('ui.quality.bucket.range', \sprintf('%d–%d kb/s', $min, $max), ['min' => $min, 'max' => $max]),
        };

        return [
            'key' => (string) $label,
            'label' => $label,
            'quality' => AudioQuality::classify($format, false, max($min, 32) * 1000)?->value,
            'sort' => $min,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * @return array{key: string, label: LabelMessage, quality: ?string, sort: int, min: ?int, max: ?int}
     */
    private function losslessBucket(?int $bits, ?int $rate): array
    {
        $khz = $rate !== null ? round($rate / 1000, 1) : null;
        $english = ($bits !== null ? $bits . ' bit' : '? bit') . ' / ' . ($khz !== null ? rtrim(rtrim(number_format($khz, 1, '.', ''), '0'), '.') . ' kHz' : '? kHz');
        $hiRes = $bits !== null && $rate !== null && ($bits > 16 || $rate > 48000);
        if ($hiRes) {
            $english .= ' (hi-res)';
        }
        // ICU formats the kHz per locale (44.1 / 44,1); "?" when unknown.
        $label = $khz !== null
            ? new LabelMessage('ui.quality.bucket.lossless', $english, ['bits' => $bits ?? '?', 'khz' => $khz, 'hires' => $hiRes ? 'yes' : 'no'])
            : new LabelMessage('ui.quality.bucket.lossless_unknown_rate', $english, ['bits' => $bits ?? '?']);

        return ['key' => $english, 'label' => $label, 'quality' => AudioQuality::Lossless->value, 'sort' => ($bits ?? 0) * 1000000 + ($rate ?? 0), 'min' => null, 'max' => null];
    }
}
