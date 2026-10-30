<?php

namespace App\Enum;

use App\Translation\LabelMessage;

/**
 * Simple, human-readable quality tier of a track.
 *
 * Raw bitrates are not comparable across codecs (AAC/Vorbis/Opus sound better
 * than MP3 at the same bitrate), so each codec family has its own thresholds:
 *
 *   MP3 (and unknown lossy): < 128 kbps very low, < 160 low, < 256 standard,
 *                            < 320 high, 320 very high — MP3 320 and AAC 256
 *                            cannot be told apart by ear.
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
     * @param ?int $bitrate       in bits per second, as stored from getID3
     * @param ?int $effectiveKbps real MP3-equivalent bitrate of an inflated file (Music::$effectiveKbps):
     *                            it is judged as an MP3 of that bitrate, even when it claims to be lossless
     */
    public static function classify(?string $dataformat, ?bool $lossless, ?int $bitrate, ?int $effectiveKbps = null): ?self
    {
        if ($effectiveKbps !== null) {
            return self::classify('mp3', false, $effectiveKbps * 1000);
        }
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
                $kbps < 256 => self::Standard,
                // 320 kb/s VBR / LAME headers often read a little under 320.
                $kbps < 310 => self::High,
                default => self::VeryHigh,
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
