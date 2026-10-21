<?php

namespace App\Service;

/**
 * ReplayGain values of a file: gains in dB, peaks linear (1.0 = full scale).
 * Read from getID3's own `replay_gain` block (ID3v2 TXXX / RVA2, LAME header,
 * Vorbis comments) and, for MP4 freeform atoms, from the tag comments.
 */
final readonly class ReplayGain
{
    public function __construct(
        public ?float $trackGain = null,
        public ?float $trackPeak = null,
        public ?float $albumGain = null,
        public ?float $albumPeak = null,
    ) {}

    /**
     * @param array<string, mixed> $info getID3 analyze() result, after CopyTagsToComments()
     */
    public static function fromGetId3(array $info): self
    {
        $rg = \is_array($info['replay_gain'] ?? null) ? $info['replay_gain'] : [];
        $comments = [];
        foreach (\is_array($info['comments'] ?? null) ? $info['comments'] : [] as $key => $values) {
            if (\is_string($key) && \is_array($values)) {
                $comments[strtolower($key)] = $values[0] ?? null;
            }
        }
        $pick = static fn(string $scope, string $field): mixed => $rg[$scope][$field === 'gain' ? 'adjustment' : 'peak']
            ?? $comments['replaygain_' . $scope . '_' . $field]
            ?? null;

        return new self(
            trackGain: self::gain($pick('track', 'gain')),
            trackPeak: self::peak($pick('track', 'peak')),
            albumGain: self::gain($pick('album', 'gain')),
            albumPeak: self::peak($pick('album', 'peak')),
        );
    }

    public function isEmpty(): bool
    {
        return $this->trackGain === null && $this->albumGain === null;
    }

    /** "-6.54 dB", "+1.2 dB" or a number → dB; absurd values are dropped. */
    private static function gain(mixed $value): ?float
    {
        $number = self::number($value);

        return $number !== null && abs($number) <= 60.0 ? round($number, 2) : null;
    }

    private static function peak(mixed $value): ?float
    {
        $number = self::number($value);

        return $number !== null && $number > 0.0 && $number <= 10.0 ? round($number, 6) : null;
    }

    private static function number(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value) && preg_match('/^\s*([+-]?\d+(?:[.,]\d+)?)/', $value, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }
}
