<?php

namespace App\Enum;

/**
 * Simple, human-readable quality tier of a track.
 *
 * Raw bitrates are not comparable across codecs (AAC/Vorbis/Opus sound better
 * than MP3 at the same bitrate), so each codec family has its own thresholds:
 *
 *   MP3 (and unknown lossy): < 160 kbps low, otherwise standard — MP3 tops out
 *                            at "standard" even at 320 kbps.
 *   AAC / Vorbis:            < 96 low, < 128 standard, < 224 high, else very high.
 *   Opus (most efficient):   < 64 low, < 96 standard, < 160 high, else very high.
 *   FLAC / ALAC / WAV (PCM): lossless, whatever the bitrate.
 */
enum AudioQuality: string
{
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
                $kbps < 64 => self::Low,
                $kbps < 96 => self::Standard,
                $kbps < 160 => self::High,
                default => self::VeryHigh,
            },
            // M4A without the lossless flag is AAC (getID3 reports 'mp4');
            // 'm4a'/'ogg' only appear when the extension was the fallback.
            'mp4', 'aac', 'm4a', 'vorbis', 'ogg' => match (true) {
                $kbps < 96 => self::Low,
                $kbps < 128 => self::Standard,
                $kbps < 224 => self::High,
                default => self::VeryHigh,
            },
            default => $kbps < 160 ? self::Low : self::Standard,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Standard => 'Standard',
            self::High => 'High quality',
            self::VeryHigh => 'Very high quality',
            self::Lossless => 'Lossless',
        };
    }
}
