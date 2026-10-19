<?php

namespace App\Service;

/**
 * Technical audio properties of a file (codec, bitrate, sample rate...),
 * extracted from a getID3 `analyze()` result. Shared by the library scan and
 * the upload form so both persist exactly the same fields.
 */
final readonly class AudioFormat
{
    public function __construct(
        public string $dataformat,
        public ?string $codec,
        public ?int $bitrate,
        public ?string $bitrateMode,
        public ?int $sampleRate,
        public ?int $bitsPerSample,
        public ?int $channels,
        public ?bool $lossless,
    ) {}

    /**
     * @param array<string, mixed> $info getID3 analyze() result
     * @param string $fallbackFormat used when getID3 reports no dataformat
     *                               (typically the file extension), so the
     *                               format column is never left null
     */
    public static function fromGetId3(array $info, string $fallbackFormat): self
    {
        /** @var array<string, mixed> $audio */
        $audio = \is_array($info['audio'] ?? null) ? $info['audio'] : [];

        $string = static function (string $key) use ($audio): ?string {
            $value = $audio[$key] ?? null;

            return \is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 128) : null;
        };
        $int = static function (string $key) use ($audio): ?int {
            $value = $audio[$key] ?? null;

            return is_numeric($value) && (int) round((float) $value) > 0 ? (int) round((float) $value) : null;
        };

        $dataformat = $string('dataformat');

        return new self(
            dataformat: mb_substr(strtolower($dataformat ?? $fallbackFormat), 0, 16),
            codec: $string('codec'),
            bitrate: $int('bitrate'),
            bitrateMode: ($mode = $string('bitrate_mode')) !== null ? mb_substr(strtolower($mode), 0, 8) : null,
            sampleRate: $int('sample_rate'),
            bitsPerSample: $int('bits_per_sample'),
            channels: $int('channels'),
            lossless: isset($audio['lossless']) ? (bool) $audio['lossless'] : null,
        );
    }
}
