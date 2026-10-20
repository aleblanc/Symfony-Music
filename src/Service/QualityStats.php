<?php

namespace App\Service;

use App\Enum\AudioQuality;

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
     * @param list<array{format: ?string, lossless: ?bool, kbps: ?int, sampleRate: ?int, bitsPerSample: ?int, n: int}> $rows
     *
     * @return array{
     *     total: int,
     *     unscanned: int,
     *     tiers: list<array{key: string, label: string, count: int, pct: float}>,
     *     formats: list<array{key: string, label: string, lossless: bool, count: int, pct: float, buckets: list<array{label: string, quality: ?string, count: int, pct: float, min: ?int, max: ?int}>}>
     * }
     */
    public function build(array $rows): array
    {
        $total = 0;
        $unscanned = 0;
        $tiers = array_fill_keys(array_map(static fn(AudioQuality $q) => $q->value, AudioQuality::cases()), 0);
        $unknownTier = 0;
        /** @var array<string, array{lossless: bool, count: int, buckets: array<string, array{label: string, quality: ?string, count: int, sort: int, min: ?int, max: ?int}>}> $formats */
        $formats = [];

        foreach ($rows as $row) {
            $n = $row['n'];
            $total += $n;
            $format = $row['format'];
            if ($format === null || $format === '') {
                $unscanned += $n;
                continue;
            }

            $quality = AudioQuality::classify($format, $row['lossless'], $row['kbps'] !== null ? $row['kbps'] * 1000 : null);
            if ($quality === null) {
                $unknownTier += $n;
            } else {
                $tiers[$quality->value] += $n;
            }

            $lossless = $quality === AudioQuality::Lossless;
            $key = $format . ($lossless && $format === 'mp4' ? '-lossless' : '');
            $formats[$key] ??= ['lossless' => $lossless, 'count' => 0, 'buckets' => []];
            $formats[$key]['count'] += $n;

            $bucket = $lossless ? $this->losslessBucket($row['bitsPerSample'], $row['sampleRate']) : $this->lossyBucket($format, $row['kbps']);
            $formats[$key]['buckets'][$bucket['label']] ??= $bucket + ['count' => 0];
            $formats[$key]['buckets'][$bucket['label']]['count'] += $n;
        }

        $pct = static fn(int $n, int $of): float => $of > 0 ? round(100 * $n / $of, 1) : 0.0;

        $tierList = [];
        if ($unknownTier > 0) {
            $tierList[] = ['key' => 'unknown', 'label' => 'Unknown bitrate', 'count' => $unknownTier, 'pct' => $pct($unknownTier, $total)];
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
                    'label' => $b['label'],
                    'quality' => $b['quality'],
                    'count' => $b['count'],
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
     * @return array{label: string, quality: ?string, sort: int, min: ?int, max: ?int}
     */
    private function lossyBucket(string $format, ?int $kbps): array
    {
        if ($kbps === null || $kbps <= 0) {
            return ['label' => 'Unknown bitrate', 'quality' => null, 'sort' => -1, 'min' => null, 'max' => null];
        }
        $steps = self::LOSSY_STEPS;
        $i = \count($steps) - 1;
        while ($steps[$i] > $kbps) {
            --$i;
        }
        $min = $steps[$i];
        $max = isset($steps[$i + 1]) ? $steps[$i + 1] - 1 : null;
        $label = match (true) {
            $min === 0 => \sprintf('< %d kb/s', $steps[1]),
            $max === null => \sprintf('≥ %d kb/s', $min),
            default => \sprintf('%d–%d kb/s', $min, $max),
        };

        return [
            'label' => $label,
            'quality' => AudioQuality::classify($format, false, max($min, 32) * 1000)?->value,
            'sort' => $min,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * @return array{label: string, quality: ?string, sort: int, min: ?int, max: ?int}
     */
    private function losslessBucket(?int $bits, ?int $rate): array
    {
        $label = ($bits !== null ? $bits . ' bit' : '? bit') . ' / ' . ($rate !== null ? rtrim(rtrim(number_format($rate / 1000, 1, '.', ''), '0'), '.') . ' kHz' : '? kHz');
        if ($bits !== null && $rate !== null && ($bits > 16 || $rate > 48000)) {
            $label .= ' (hi-res)';
        }

        return ['label' => $label, 'quality' => AudioQuality::Lossless->value, 'sort' => ($bits ?? 0) * 1000000 + ($rate ?? 0), 'min' => null, 'max' => null];
    }
}
