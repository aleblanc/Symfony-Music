<?php

namespace App\Enum;

use App\Translation\LabelMessage;

/**
 * Simple, human-readable quality tier of a track.
 *
 * Raw bitrates are not comparable across codecs (AAC/Vorbis/Opus sound better
 * than MP3 at the same bitrate), so each codec family has its own thresholds:
 *
 *   MP3 (and unknown lossy): < 128 kbps very low, < 160 low, otherwise standard —
 *                            MP3 tops out at "standard" even at 320 kbps.
 *   AAC / Vorbis:            < 64 very low, < 96 low, < 128 standard, < 224 high, else very high.
 *   Opus (most efficient):   < 48 very low, < 64 low, < 96 standard, < 160 high, else very high.
 *   FLAC / ALAC / WAV (PCM): lossless, whatever the bitrate.
 */
enum AudioQuality: string
{
    case VeryLow = 'very_low';
    case Low = 'low';
    case Standard = 'standard';
    case High = 'high';
    case VeryHigh = 'very_high';
    case Lossless = 'lossless';

    /**
     * @param ?int $bitrate in bits per second, as stored from getID3
     */
    public static function classify(?string $dataformat, ?bool $lossless, ?int $bitrate): ?self
    {
        if ($lossless === true || \in_array($dataformat, ['flac', 'alac', 'wav', 'aiff'], true)) {
            return self::Lossless;
        }
        if ($bitrate === null || $bitrate <= 0) {
            return null;
        }

        $kbps = $bitrate / 1000;

        return match ($dataformat) {
            'opus' => match (true) {
                $kbps < 48 => self::VeryLow,
                $kbps < 64 => self::Low,
                $kbps < 96 => self::Standard,
                $kbps < 160 => self::High,
                default => self::VeryHigh,
            },
            // M4A without the lossless flag is AAC (getID3 reports 'mp4');
            // 'm4a'/'ogg' only appear when the extension was the fallback.
            'mp4', 'aac', 'm4a', 'vorbis', 'ogg' => match (true) {
                $kbps < 64 => self::VeryLow,
                $kbps < 96 => self::Low,
                $kbps < 128 => self::Standard,
                $kbps < 224 => self::High,
                default => self::VeryHigh,
            },
            default => match (true) {
                $kbps < 128 => self::VeryLow,
                $kbps < 160 => self::Low,
                default => self::Standard,
            },
        };
    }

    /**
     * Translatable label (Twig: `{{ q.label|trans }}`); casts to the English
     * text for console output.
     */
    public function label(): LabelMessage
    {
        return new LabelMessage('ui.quality.' . $this->value, $this->englishLabel());
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::VeryLow => 'Very low',
            self::Low => 'Low',
            self::Standard => 'Standard',
            self::High => 'High quality',
            self::VeryHigh => 'Very high quality',
            self::Lossless => 'Lossless',
        };
    }
}
