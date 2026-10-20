<?php

namespace App\Service\Import;

use App\Service\BinaryLocator;
use Symfony\Component\Process\Process;

/**
 * Converts the WMA files of the import folder with ffmpeg: browsers cannot
 * play WMA and getID3 cannot tag it. Every WMA becomes M4A (AAC, 320 kb/s
 * for WMA Lossless); tags and cover are carried over. The original is
 * moved to IMPORT_DIR/_wma, never deleted. `app:import` then identifies and
 * files the converted files like any other.
 */
class WmaConverter
{
    public const ORIGINALS_DIR = '_wma';

    /** AAC bitrates (kb/s) by source bitrate: never below the source, never pointlessly above. */
    private const AAC_STEPS = [[256, 320], [160, 256], [0, 192]];

    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly string $ffmpegPath = '',
    ) {}

    public function binaryPath(): ?string
    {
        return $this->locator->find('ffmpeg', $this->ffmpegPath);
    }

    /**
     * @return list<string> WMA files under $root (originals, duplicates and hidden folders skipped)
     */
    public function findWma(string $root): array
    {
        $root = rtrim($root, '/');
        $skip = [$root . '/' . self::ORIGINALS_DIR, $root . '/' . FileImporter::DUPLICATES_DIR];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            static fn(\SplFileInfo $f): bool => !str_starts_with($f->getFilename(), '.')
                && ($f->isDir() ? !\in_array($f->getPathname(), $skip, true) : strtolower($f->getExtension()) === 'wma'),
        ));

        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /**
     * @param ?int $forcedKbps AAC bitrate to use instead of the automatic choice
     *
     * @return array{lossless: bool, kbps: int, sourceKbps: ?int, target: string, duration: float}
     */
    public function plan(string $file, ?int $forcedKbps): array
    {
        $info = (new \getID3())->analyze($file);
        $lossless = (bool) ($info['audio']['lossless'] ?? false) || stripos((string) ($info['audio']['codec'] ?? ''), 'lossless') !== false;
        $sourceKbps = isset($info['audio']['bitrate']) ? (int) round((float) $info['audio']['bitrate'] / 1000) : null;

        // WMA Lossless gets the top AAC bitrate; lossy WMA a step above its own.
        $kbps = $forcedKbps ?? ($lossless ? self::AAC_STEPS[0][1] : null);
        foreach (self::AAC_STEPS as [$from, $aac]) {
            if ($kbps === null && ($sourceKbps ?? 0) >= $from) {
                $kbps = $aac;
            }
        }

        return [
            'lossless' => $lossless,
            'kbps' => $kbps ?? self::AAC_STEPS[0][1],
            'sourceKbps' => $sourceKbps,
            'target' => substr($file, 0, -\strlen(pathinfo($file, \PATHINFO_EXTENSION))) . 'm4a',
            'duration' => (float) ($info['playtime_seconds'] ?? 0),
        ];
    }

    /**
     * @return string the converted file
     *
     * @throws \RuntimeException when ffmpeg is missing, the target exists or the conversion fails (the WMA is left in place)
     */
    public function convert(string $file, string $root, ?int $forcedKbps): string
    {
        $ffmpeg = $this->binaryPath() ?? throw new \RuntimeException('ffmpeg not found: brew install ffmpeg (or apt install ffmpeg), or set FFMPEG_PATH');
        $plan = $this->plan($file, $forcedKbps);
        $target = $plan['target'];
        if (file_exists($target)) {
            throw new \RuntimeException(\sprintf('"%s" already exists, not overwritten', basename($target)));
        }

        $tmp = \dirname($target) . '/.' . basename($target, '.' . pathinfo($target, \PATHINFO_EXTENSION)) . '.converting.' . pathinfo($target, \PATHINFO_EXTENSION);
        $codec = ['-c:a', 'aac', '-b:a', $plan['kbps'] . 'k', '-movflags', '+faststart'];
        // The cover (a picture stream in the WMA) is copied when there is one;
        // if the container refuses it, the audio is converted without it.
        $withCover = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $file,
            '-map', '0:a:0', '-map', '0:v:0?', '-map_metadata', '0', ...$codec, '-c:v', 'copy', '-disposition:v:0', 'attached_pic', $tmp];
        $audioOnly = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $file,
            '-map', '0:a:0', '-map_metadata', '0', ...$codec, $tmp];

        $error = '';
        foreach ([$withCover, $audioOnly] as $command) {
            $process = new Process($command, null, null, null, 600);
            $process->run();
            if ($process->isSuccessful() && is_file($tmp)) {
                $error = '';
                break;
            }
            $error = trim($process->getErrorOutput());
            @unlink($tmp);
        }
        if ($error !== '' || !is_file($tmp)) {
            throw new \RuntimeException('ffmpeg failed: ' . ($error !== '' ? $error : 'no output'));
        }

        // The result must be audio of the same length.
        $after = (new \getID3())->analyze($tmp);
        $duration = (float) ($after['playtime_seconds'] ?? 0);
        if (!isset($after['audio']['dataformat']) || ($plan['duration'] > 0 && abs($duration - $plan['duration']) > 1.5)) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('converted file looks wrong (%.1f s instead of %.1f s), WMA kept', $duration, $plan['duration']));
        }

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot rename the converted file');
        }

        $original = rtrim($root, '/') . '/' . self::ORIGINALS_DIR . '/' . ltrim(substr($file, \strlen(rtrim($root, '/'))), '/');
        if (!is_dir(\dirname($original)) && !mkdir(\dirname($original), 0775, true) && !is_dir(\dirname($original))) {
            throw new \RuntimeException(\sprintf('converted, but cannot create "%s" for the original', \dirname($original)));
        }
        if (!rename($file, $original)) {
            throw new \RuntimeException('converted, but the original could not be moved to ' . self::ORIGINALS_DIR);
        }

        return $target;
    }
}
