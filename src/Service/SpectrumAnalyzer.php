<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * Finds where a track's treble stops, to catch inflated bitrates: a "320"
 * MP3 made from a 128 one keeps the 128's encoder lowpass (a wall at
 * 17 kHz) whatever its header says. ffmpeg decodes three 8 s slices (at
 * 20 %, 50 % and 80 % of the track) to mono 44.1 kHz PCM; the average
 * spectrum of up to 120 FFT frames gives the cutoff. A cutoff is "sharp"
 * when the level falls by 25 dB or more within about 600 Hz (an encoder's
 * lowpass) and the slices loud enough near it agree: an encoder's wall is
 * in every passage, a quiet or filtered one is not. A natural roll-off (old
 * recording, solo piano) is never taken for a lower bitrate.
 */
class SpectrumAnalyzer
{
    private const RATE = 44100;
    private const N = 2048;
    private const FRAMES = 120;
    private const SLICE = 8;

    /** MP3-equivalent bitrate of a sharp cutoff, LAME's lowpass per bitrate: [below this Hz, kbps]. */
    private const MP3_EQUIVALENT = [[15500, 96], [16500, 112], [17300, 128], [18000, 160], [19000, 192], [19600, 224], [20300, 256]];

    /**
     * The lowest sharp cutoff a genuine encode has, per declared bitrate: [at least this kbps, Hz].
     * Not below 224 kb/s for MP3, 192 for AAC / Vorbis / Opus: old encoders (FhG, Xing) cut a genuine
     * 128–192 at 15–16 kHz. And never above 19 kHz: older LAME cut a genuine 320 a little under 20 kHz,
     * and nobody hears the difference up there.
     */
    private const EXPECTED_MP3 = [[256, 19000], [224, 18600]];
    private const EXPECTED_EFFICIENT = [[256, 19000], [192, 17500]];
    private const EXPECTED_LOSSLESS = 19000;

    private ?string $ffmpeg = null;

    /** @var array<int, int> slices decoded per running process (spl_object_id) */
    private array $slices = [];

    public function __construct(
        private readonly string $ffmpegPath = '',
    ) {}

    /**
     * @return ?array{cutoffHz: int, sharp: bool, sections: list<?array{cutoffHz: int, sharp: bool}>} null when the file cannot be decoded or is silent
     *
     * @throws \RuntimeException when ffmpeg is missing
     */
    public function analyze(string $file, ?int $duration): ?array
    {
        $process = $this->start($file, $duration);
        $process->wait();

        return $this->result($process);
    }

    /**
     * Starts ffmpeg on the three slices without waiting (several files at once:
     * start each, then result() when it is done).
     *
     * @throws \RuntimeException when ffmpeg is missing
     */
    public function start(string $file, ?int $duration): Process
    {
        $this->ffmpeg ??= (new BinaryLocator())->find('ffmpeg', $this->ffmpegPath)
            ?? throw new \RuntimeException('ffmpeg not found: install it or set FFMPEG_PATH');
        $duration = max(0, (int) $duration);
        $starts = $duration > 3 * self::SLICE + 4
            ? array_map(static fn(float $at): int => (int) ($duration * $at), [0.2, 0.5, 0.8])
            : [0];
        $command = [$this->ffmpeg, '-v', 'error', '-nostdin'];
        foreach ($starts as $start) {
            array_push($command, '-ss', (string) $start, '-t', (string) self::SLICE, '-i', $file);
        }
        if (\count($starts) > 1) {
            $inputs = implode('', array_map(static fn(int $i): string => "[{$i}:a]", array_keys($starts)));
            array_push($command, '-filter_complex', $inputs . 'concat=n=' . \count($starts) . ':v=0:a=1');
        }
        array_push($command, '-ac', '1', '-ar', (string) self::RATE, '-f', 's16le', '-');

        $process = new Process($command, null, null, null, 120);
        $process->start();
        $this->slices[spl_object_id($process)] = \count($starts);

        return $process;
    }

    /** @return ?array{cutoffHz: int, sharp: bool, sections: list<?array{cutoffHz: int, sharp: bool}>} null when the file could not be decoded or is silent */
    public function result(Process $process): ?array
    {
        $pcm = $process->getOutput();
        $slices = $this->slices[spl_object_id($process)] ?? 1;
        unset($this->slices[spl_object_id($process)]);

        return $process->isSuccessful() && $pcm !== '' ? $this->cutoff($pcm, $slices) : null;
    }

    /**
     * @param string $pcm    mono signed 16-bit little-endian samples at 44.1 kHz
     * @param int    $slices how many slices of equal length $pcm is made of: each one loud enough
     *                       near the wall must show it too (an encoder's wall is everywhere, a quiet
     *                       or filtered passage is not); `sections` gives each slice's own cutoff
     *
     * @return ?array{cutoffHz: int, sharp: bool, sections: list<?array{cutoffHz: int, sharp: bool}>} null when too short or silent
     */
    public function cutoff(string $pcm, int $slices = 1): ?array
    {
        $total = intdiv(\strlen($pcm), 2);
        if ($total < self::N) {
            return null;
        }
        $slices = max(1, min($slices, intdiv($total, self::N)));
        $bins = self::N / 2;
        $window = [];
        for ($i = 0; $i < self::N; ++$i) {
            $window[$i] = 0.5 - 0.5 * cos(2 * M_PI * $i / (self::N - 1));
        }
        $count = min(self::FRAMES, intdiv($total, self::N));
        $step = intdiv($total - self::N, max(1, $count - 1));
        $sliceLength = intdiv($total, $slices);
        $power = array_fill(0, $slices, array_fill(0, $bins, 0.0));
        $frames = array_fill(0, $slices, 0);
        for ($f = 0; $f < $count; ++$f) {
            $offset = $f * $step;
            /** @var array<int, int> $samples */
            $samples = unpack('v' . self::N, $pcm, $offset * 2) ?: [];
            $re = [];
            $i = 0;
            foreach ($samples as $s) {
                $re[$i] = ($s >= 0x8000 ? $s - 0x10000 : $s) / 32768 * $window[$i];
                ++$i;
            }
            $im = array_fill(0, self::N, 0.0);
            self::fft($re, $im);
            $slice = min($slices - 1, intdiv($offset + self::N / 2, $sliceLength));
            ++$frames[$slice];
            for ($k = 0; $k < $bins; ++$k) {
                $power[$slice][$k] += $re[$k] * $re[$k] + $im[$k] * $im[$k];
            }
        }

        // Whole: the power of every frame (a quiet passage weighs little next to a loud one).
        $all = array_fill(0, $bins, 0.0);
        foreach ($power as $slicePower) {
            foreach ($slicePower as $k => $p) {
                $all[$k] += $p;
            }
        }
        $whole = self::edge(self::smooth($all, $count), fn(int $at): bool => $this->sliceVote($power, $frames, $at));
        if ($whole === null) {
            return null; // silence
        }

        // Each slice on its own (no vote), in order: where the treble stops in each passage,
        // null for a silent one.
        $sections = [];
        foreach ($power as $i => $slicePower) {
            $sections[] = $frames[$i] > 0 ? self::edge(self::smooth($slicePower, $frames[$i]), null) : null;
        }

        return $whole + ['sections' => $sections];
    }

    /**
     * Where the treble stops in an average spectrum: a sharp wall (when $vote,
     * if given, agrees), else how far the content goes.
     *
     * @param array<int, float>        $smooth
     * @param ?\Closure(int): bool     $vote   whether the slices agree on a wall at this bin
     *
     * @return ?array{cutoffHz: int, sharp: bool} null when silent
     */
    private static function edge(array $smooth, ?\Closure $vote): ?array
    {
        $bins = \count($smooth);
        $reference = self::reference($smooth);
        if ($reference < -100) {
            return null;
        }

        // Sharp cutoff: the biggest fall between 300–1200 Hz below and 300–1200 Hz above.
        $best = 0.0;
        $at = 0;
        // Up to 21 kHz: a wall above never gives away a weaker source.
        for ($k = self::bin(11000); $k <= self::bin(21000); ++$k) {
            $drop = self::dropAt($smooth, $k);
            if ($drop === null) {
                break;
            }
            if ($drop > $best) {
                $best = $drop;
                $at = $k;
            }
        }
        $before = $at > 0 ? self::mean($smooth, $at - self::bin(1200), $at - self::bin(300)) : -200.0;
        if ($best >= 25 && $before > $reference - 90 && ($vote === null || $vote($at))) {
            // The wall: first bin from 300 Hz below that is 15 dB under the level before it.
            for ($k = $at - self::bin(300); $k < $bins && $smooth[$k] > $before - 15; ++$k) {
            }

            return ['cutoffHz' => (int) round($k * self::RATE / self::N), 'sharp' => true];
        }

        // No wall: how far the content goes (90 dB under the loudest low/mid level).
        for ($k = $bins - 1; $k > 0 && $smooth[$k] < $reference - 90; --$k) {
        }

        return ['cutoffHz' => (int) round($k * self::RATE / self::N), 'sharp' => false];
    }

    /**
     * Whether the slices agree on a wall at bin $at: those with content just
     * below it (not more than 80 dB under their own loudest low/mid level)
     * vote, a majority must show a fall of 20 dB within 300 Hz of it, and at
     * least one must vote.
     *
     * @param array<int, array<int, float>> $power  summed power per slice and bin
     * @param array<int, int>               $frames FFT frames per slice
     */
    private function sliceVote(array $power, array $frames, int $at): bool
    {
        $voters = 0;
        $agree = 0;
        foreach ($power as $i => $slicePower) {
            if ($frames[$i] === 0) {
                continue;
            }
            $smooth = self::smooth($slicePower, $frames[$i]);
            $reference = self::reference($smooth);
            if ($reference < -100 || self::mean($smooth, $at - self::bin(1200), $at - self::bin(300)) < $reference - 80) {
                continue; // too quiet near the wall to tell
            }
            ++$voters;
            for ($k = $at - self::bin(300); $k <= $at + self::bin(300); ++$k) {
                if ((self::dropAt($smooth, $k) ?? 0) >= 20) {
                    ++$agree;
                    break;
                }
            }
        }

        return $voters > 0 && $agree * 2 > $voters;
    }

    /**
     * Average spectrum in dB, smoothed over ±3 bins (about ±65 Hz).
     *
     * @param array<int, float> $power summed power per bin
     *
     * @return array<int, float>
     */
    private static function smooth(array $power, int $frames): array
    {
        $db = array_map(static fn(float $p): float => 10 * log10($p / max(1, $frames) + 1e-20), $power);
        $bins = \count($db);
        $smooth = [];
        for ($k = 0; $k < $bins; ++$k) {
            $lo = max(0, $k - 3);
            $hi = min($bins - 1, $k + 3);
            $smooth[$k] = (float) array_sum(\array_slice($db, $lo, $hi - $lo + 1)) / ($hi - $lo + 1);
        }

        return $smooth;
    }

    /** @param array<int, float> $smooth The loudest low/mid level (100 Hz–5 kHz), in dB. */
    private static function reference(array $smooth): float
    {
        return max(-200.0, ...\array_slice($smooth, self::bin(100), self::bin(5000) - self::bin(100)));
    }

    /**
     * Fall from 300–1200 Hz below bin $k to 300–1200 Hz above it, in dB;
     * null when there is not enough room above (near Nyquist).
     *
     * @param array<int, float> $smooth
     */
    private static function dropAt(array $smooth, int $k): ?float
    {
        $top = min(\count($smooth) - 1, $k + self::bin(1200));
        if ($top - ($k + self::bin(300)) < self::bin(300) || $k - self::bin(1200) < 0) {
            return null;
        }

        return self::mean($smooth, $k - self::bin(1200), $k - self::bin(300)) - self::mean($smooth, $k + self::bin(300), $top);
    }

    /** @param array<int, float> $smooth */
    private static function mean(array $smooth, int $from, int $to): float
    {
        return array_sum(\array_slice($smooth, $from, $to - $from + 1)) / ($to - $from + 1);
    }

    private static function bin(float $hz): int
    {
        return (int) round($hz * self::N / self::RATE);
    }

    /**
     * The real bitrate (MP3-equivalent kbps) of a file whose sharp cutoff is
     * lower than any genuine encode at its declared bitrate; null when it
     * looks genuine.
     */
    public static function effectiveKbps(?string $format, ?bool $lossless, ?int $bitrate, int $cutoffHz, bool $sharp): ?int
    {
        if (!$sharp || $cutoffHz <= 0) {
            return null;
        }
        if ($lossless === true || \in_array($format, ['flac', 'alac', 'wav', 'aiff'], true)) {
            $expected = self::EXPECTED_LOSSLESS;
        } else {
            $kbps = (int) round(($bitrate ?? 0) / 1000);
            $expected = null;
            $table = \in_array($format, ['mp4', 'aac', 'm4a', 'vorbis', 'ogg', 'opus'], true) ? self::EXPECTED_EFFICIENT : self::EXPECTED_MP3;
            foreach ($table as [$min, $hz]) {
                if ($kbps >= $min) {
                    $expected = $hz;
                    break;
                }
            }
            if ($expected === null) {
                return null; // low bitrates cut low anyway
            }
        }
        if ($cutoffHz >= $expected) {
            return null;
        }

        return self::mp3EquivalentKbps($cutoffHz);
    }

    /** The MP3 bitrate whose LAME lowpass sits at a sharp cutoff of $cutoffHz (128 for 17 kHz). */
    public static function mp3EquivalentKbps(int $cutoffHz): int
    {
        foreach (self::MP3_EQUIVALENT as [$below, $kbps]) {
            if ($cutoffHz < $below) {
                return $kbps;
            }
        }

        return 320;
    }

    /**
     * In-place iterative radix-2 FFT.
     *
     * @param array<int, float> $re
     * @param array<int, float> $im
     */
    private static function fft(array &$re, array &$im): void
    {
        $n = \count($re);
        for ($i = 1, $j = 0; $i < $n; ++$i) {
            $bit = $n >> 1;
            for (; $j & $bit; $bit >>= 1) {
                $j ^= $bit;
            }
            $j ^= $bit;
            if ($i < $j) {
                [$re[$i], $re[$j]] = [$re[$j], $re[$i]];
                [$im[$i], $im[$j]] = [$im[$j], $im[$i]];
            }
        }
        for ($len = 2; $len <= $n; $len <<= 1) {
            $half = $len >> 1;
            $angle = -2 * M_PI / $len;
            $wr = [];
            $wi = [];
            for ($k = 0; $k < $half; ++$k) {
                $wr[$k] = cos($angle * $k);
                $wi[$k] = sin($angle * $k);
            }
            for ($i = 0; $i < $n; $i += $len) {
                for ($k = 0; $k < $half; ++$k) {
                    $a = $i + $k;
                    $b = $a + $half;
                    $tr = $re[$b] * $wr[$k] - $im[$b] * $wi[$k];
                    $ti = $re[$b] * $wi[$k] + $im[$b] * $wr[$k];
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                }
            }
        }
    }
}
