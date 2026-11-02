<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * The waveform of the player's progress bar, coloured by frequency: ffmpeg
 * splits the track in three bands (bass < 250 Hz, mids, treble > 4 kHz) and
 * writes the loudness envelope of the whole signal and of each band, 50
 * frames a second; those frames become BARS bars, each with its height
 * (loudest frame) and the share of bass, mids and treble (average), all
 * 0-100. Saved as var/waveforms/<id>.json, made again when the audio file
 * changes. The directory can be deleted at any time.
 */
class WaveformGenerator
{
    public const BARS = 600;
    /** Envelope frames per second written by ffmpeg. */
    private const RATE = 50;
    private const FILTER = '[0:a]aformat=sample_fmts=fltp:channel_layouts=mono,aresample=22050,asplit=4[a][b][c][d];'
        . '[b]lowpass=f=250,lowpass=f=250[l];'
        . '[c]highpass=f=250,highpass=f=250,lowpass=f=4000,lowpass=f=4000[m];'
        . '[d]highpass=f=4000,highpass=f=4000[h];'
        // join's default order is not its inputs' one: mapped explicitly.
        . '[a][l][m][h]join=inputs=4:channel_layout=4.0:map=0.0-FL|1.0-FR|2.0-FC|3.0-BC,'
        . 'aeval=abs(val(0))|abs(val(1))|abs(val(2))|abs(val(3)),aresample=' . self::RATE;

    private readonly string $dir;
    private ?string $ffmpeg = null;

    public function __construct(
        string $projectDir,
        private readonly string $ffmpegPath = '',
    ) {
        $this->dir = $projectDir . '/var/waveforms';
    }

    /**
     * The waveform of a track from its cache file, made and saved when missing
     * or older than the audio file. Null when ffmpeg cannot read it.
     *
     * @return ?array{bars: int, peak: list<int>, low: list<int>, mid: list<int>, high: list<int>}
     */
    public function forFile(int $id, string $file): ?array
    {
        $cache = $this->dir . '/' . $id . '.json';
        if (is_file($cache) && filemtime($cache) >= (int) @filemtime($file)) {
            $waveform = json_decode((string) file_get_contents($cache), true);
            if (\is_array($waveform) && isset($waveform['bars'], $waveform['peak'])) {
                return $waveform;
            }
        }
        $waveform = $this->generate($file);
        if ($waveform !== null) {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            // Written aside then renamed: a reader never sees half a file.
            $tmp = $cache . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($waveform)) !== false) {
                @rename($tmp, $cache);
            }
        }

        return $waveform;
    }

    /**
     * @return ?array{bars: int, peak: list<int>, low: list<int>, mid: list<int>, high: list<int>}
     */
    public function generate(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $this->ffmpeg ??= (new BinaryLocator())->find('ffmpeg', $this->ffmpegPath)
            ?? throw new \RuntimeException('ffmpeg not found: install it or set FFMPEG_PATH');
        $process = new Process([$this->ffmpeg, '-v', 'error', '-nostdin', '-i', $file, '-filter_complex', self::FILTER,
            '-f', 'f32le', '-acodec', 'pcm_f32le', '-'], null, null, null, 120);
        $process->run();

        return $process->isSuccessful() ? self::fromEnvelope($process->getOutput()) : null;
    }

    /**
     * Bars from the envelope ffmpeg writes: 4 little-endian floats per frame
     * (whole signal, bass, mids, treble). Null when it is empty or silent.
     *
     * @return ?array{bars: int, peak: list<int>, low: list<int>, mid: list<int>, high: list<int>}
     */
    public static function fromEnvelope(string $f32le, int $bars = self::BARS): ?array
    {
        $frames = intdiv(\strlen($f32le), 16);
        if ($frames === 0) {
            return null;
        }
        $values = array_values(unpack('g' . ($frames * 4), $f32le) ?: []);
        $bars = min($bars, $frames);
        $peak = $low = $mid = $high = [];
        for ($b = 0; $b < $bars; ++$b) {
            $from = intdiv($b * $frames, $bars);
            $to = max($from + 1, intdiv(($b + 1) * $frames, $bars));
            $max = 0.0;
            $sums = [0.0, 0.0, 0.0];
            for ($f = $from; $f < $to; ++$f) {
                // The resampler rings a little below zero around silence.
                $max = max($max, $values[$f * 4]);
                for ($band = 0; $band < 3; ++$band) {
                    $sums[$band] += max(0.0, $values[$f * 4 + 1 + $band]);
                }
            }
            $peak[] = $max;
            $low[] = $sums[0] / ($to - $from);
            $mid[] = $sums[1] / ($to - $from);
            $high[] = $sums[2] / ($to - $from);
        }
        if (max($peak) < 1e-4) {
            return null;
        }

        // Each band against its own loudest bar: bass always carries the most
        // energy, so raw levels would colour every bar as bass.
        return ['bars' => $bars, 'peak' => self::scale($peak), 'low' => self::scale($low), 'mid' => self::scale($mid), 'high' => self::scale($high)];
    }

    /**
     * @param list<float> $values
     *
     * @return list<int> 0-100, the largest value 100
     */
    private static function scale(array $values): array
    {
        $max = max($values);

        return array_map(static fn (float $v): int => $max > 1e-6 ? (int) round(max(0.0, $v) / $max * 100) : 0, $values);
    }
}
