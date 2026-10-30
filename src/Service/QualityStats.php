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
     *     formats: list<array{key: string, label: string, lossless: bool, count: int, pct: float, buckets: list<array{key: string, label: LabelMessage, quality: ?string, count: int, inflated: int, pct: float, min: ?int, max: ?int}>}>
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

            $lossless = $declared === AudioQuality::Lossless;
            $key = $format . ($lossless && $format === 'mp4' ? '-lossless' : '');
            $formats[$key] ??= ['lossless' => $lossless, 'count' => 0, 'buckets' => []];
            $formats[$key]['count'] += $n;

            $bucket = $lossless ? $this->losslessBucket($row['bitsPerSample'], $row['sampleRate']) : $this->lossyBucket($format, $row['kbps']);
            $formats[$key]['buckets'][$bucket['key']] ??= $bucket + ['count' => 0, 'inflated' => 0];
            $formats[$key]['buckets'][$bucket['key']]['count'] += $n;
            // Of which inflated: their real quality is lower than the range says.
            if (($row['effectiveKbps'] ?? null) !== null) {
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
                'buckets' => array_map(static fn(array $b) => [
                    'key' => $b['key'],
                    'label' => $b['label'],
                    'quality' => $b['quality'],
                    'count' => $b['count'],
                    'inflated' => $b['inflated'],
                    'pct' => $pct($b['count'], $f['count']),
                    'min' => $b['min'],
                    'max' => $b['max'],
                ], $buckets),
            ];
        }
        // Worst formats first: lossy before lossless, then by share of the library.
        usort($formatList, static fn(array $a, array $b) => [$a['lossless'], -$a['count']] <=> [$b['lossless'], -$b['count']]);

        return ['total' => $total, 'unscanned' => $unscanned, 'tiers' => $tierList, 'formats' => $formatList];
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
