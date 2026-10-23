<?php

namespace App\Service\Import;

use App\Service\BinaryLocator;
use Symfony\Component\Process\Process;

/**
 * `app:import --flac-to-aac`: FLAC files of the import folder become M4A
 * (AAC 256 kb/s, transparent and about 4 times smaller) before being
 * identified and filed. Apple's AAC encoder is used when ffmpeg has it
 * (macOS), else ffmpeg's own; tags and cover are carried over. The FLAC is
 * moved to IMPORT_DIR/_flac, never deleted.
 */
class FlacConverter
{
    public const ORIGINALS_DIR = '_flac';
    private const KBPS = 256;

    private ?string $encoder = null;

    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly string $ffmpegPath = '',
    ) {}

    public function binaryPath(): ?string
    {
        return $this->locator->find('ffmpeg', $this->ffmpegPath);
    }

    /** 'aac_at' (Apple, macOS) when ffmpeg has it, else 'aac'. */
    public function encoder(): string
    {
        if ($this->encoder === null) {
            $process = new Process([(string) $this->binaryPath(), '-hide_banner', '-encoders']);
            $process->run();
            $this->encoder = str_contains($process->getOutput(), ' aac_at ') ? 'aac_at' : 'aac';
        }

        return $this->encoder;
    }

    /**
     * @return list<string> FLAC files under $root (originals, duplicates and hidden folders skipped)
     */
    public function findFlac(string $root): array
    {
        $root = rtrim($root, '/');
        $skip = [$root . '/' . self::ORIGINALS_DIR, $root . '/' . FileImporter::DUPLICATES_DIR];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            static fn(\SplFileInfo $f): bool => !str_starts_with($f->getFilename(), '.')
                && ($f->isDir() ? !\in_array($f->getPathname(), $skip, true) : strtolower($f->getExtension()) === 'flac'),
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
     * @return string the .m4a now in the FLAC's place
     *
     * @throws \RuntimeException when ffmpeg is missing, the target exists or the conversion fails (the FLAC is left in place)
     */
    public function convert(string $file, string $root): string
    {
        $ffmpeg = $this->binaryPath() ?? throw new \RuntimeException('ffmpeg not found: brew install ffmpeg (or apt install ffmpeg), or set FFMPEG_PATH');
        $target = substr($file, 0, -\strlen(pathinfo($file, \PATHINFO_EXTENSION))) . 'm4a';
        if (file_exists($target)) {
            throw new \RuntimeException(\sprintf('"%s" already exists, not overwritten', basename($target)));
        }
        $before = (float) ((new \getID3())->analyze($file)['playtime_seconds'] ?? 0);

        $tmp = \dirname($target) . '/.' . basename($target, '.m4a') . '.converting.m4a';
        $codec = ['-c:a', $this->encoder(), '-b:a', self::KBPS . 'k', '-movflags', '+faststart'];
        // The cover (a picture block in the FLAC) is copied when there is one;
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
        $after = (float) ((new \getID3())->analyze($tmp)['playtime_seconds'] ?? 0);
        if ($before > 0 && abs($after - $before) > 1.5) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('converted file looks wrong (%.1f s instead of %.1f s), FLAC kept', $after, $before));
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
