<?php

namespace App\Service\Import;

use App\Service\CoverImageProcessor;
use App\Service\Enrichment\Proposal;
use App\Service\MetadataWriter;

/**
 * Files one identified track: writes its tags (+ cover, + MusicBrainz ids),
 * then moves it from the import folder to MUSIC_DIR at {@see LibraryPath}.
 *
 * - The tags are written first: if that fails, nothing moves.
 * - A byte-identical file already at the destination is a duplicate: the
 *   import copy goes to IMPORT_DIR/_duplicates instead.
 * - A different file at the destination is never overwritten: " (2)"...
 * - cover.jpg is added next to the album when missing; import folders left
 *   empty are removed.
 */
class FileImporter
{
    public const DUPLICATES_DIR = '_duplicates';

    public function __construct(
        private readonly MetadataWriter $writer,
        private readonly CoverImageProcessor $images,
        private readonly LibraryPath $libraryPath,
        private readonly string $musicDir,
    ) {}

    /**
     * @param ?string $cover front cover bytes to embed and save as cover.jpg (null: keep what the file has)
     *
     * @throws \InvalidArgumentException when the file is not inside the import folder
     * @throws \RuntimeException when tagging or moving fails (the file is left where it was)
     */
    public function import(string $file, string $importRoot, Proposal $proposal, ?string $cover): ImportResult
    {
        $importRoot = rtrim($importRoot, '/');
        $real = realpath($file);
        $realRoot = realpath($importRoot);
        if ($real === false || $realRoot === false || !str_starts_with($real, $realRoot . '/')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not inside the import folder.', $file));
        }

        $ext = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        $jpeg = $cover !== null ? ($this->images->toJpeg($cover) ?? $cover) : null;
        $relative = $this->libraryPath->relativePath($proposal->fields, $ext);
        $destination = rtrim($this->musicDir, '/') . '/' . $relative;

        // Same audio already filed (tags may differ): no need to tag it.
        if (is_file($destination) && $this->sameAudio($file, $destination)) {
            $duplicate = $this->freePath($importRoot . '/' . self::DUPLICATES_DIR . '/' . $relative);
            $this->move($file, $duplicate);
            $this->pruneEmptyDirs(\dirname($file), $importRoot);

            return new ImportResult(ImportResult::DUPLICATE, $duplicate);
        }

        // WAV (and M4A without AtomicParsley) cannot carry tags: only filed.
        if ($this->writer->canWrite($ext)) {
            $fields = $proposal->fields + [
                'musicbrainzRecordingId' => $proposal->recordingId,
                'musicbrainzReleaseId' => $proposal->releaseId,
                'musicbrainzReleaseGroupId' => $proposal->releaseGroupId,
            ];
            if (!$this->writer->writeTags($file, $fields, $jpeg)) {
                throw new \RuntimeException('tags not written: ' . ($this->writer->lastError() ?? 'unknown error'));
            }
        }

        $destination = $this->freePath($destination);
        $this->move($file, $destination);

        $coverFile = \dirname($destination) . '/cover.jpg';
        if ($jpeg !== null && ($proposal->fields['album'] ?? null) !== null && !is_file($coverFile)) {
            file_put_contents($coverFile, $jpeg);
        }
        $this->pruneEmptyDirs(\dirname($file), $importRoot);

        return new ImportResult(ImportResult::IMPORTED, $destination);
    }

    /** Same audio stream, whatever the tags (getID3 hashes the data without them). */
    private function sameAudio(string $a, string $b): bool
    {
        $hash = static function (string $file): ?string {
            $getID3 = new \getID3();
            $getID3->setOption(['option_md5_data' => true, 'option_md5_data_source' => true]);
            $info = $getID3->analyze($file);

            return isset($info['md5_data']) && \is_string($info['md5_data']) ? $info['md5_data'] : null;
        };
        $hashA = $hash($a);

        return $hashA !== null && $hashA === $hash($b);
    }

    /** "Title.mp3", else "Title (2).mp3", "Title (3).mp3"... */
    private function freePath(string $path): string
    {
        if (!file_exists($path)) {
            return $path;
        }
        $dir = \dirname($path);
        $name = pathinfo($path, \PATHINFO_FILENAME);
        $ext = pathinfo($path, \PATHINFO_EXTENSION);
        for ($i = 2; ; ++$i) {
            $candidate = \sprintf('%s/%s (%d)%s', $dir, $name, $i, $ext !== '' ? '.' . $ext : '');
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    private function move(string $from, string $to): void
    {
        $dir = \dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('cannot create "%s"', $dir));
        }
        // rename() fails across file systems (import folder on another disk).
        if (!@rename($from, $to)) {
            if (!@copy($from, $to)) {
                throw new \RuntimeException(\sprintf('cannot move to "%s"', $to));
            }
            if (!@unlink($from)) {
                @unlink($to);
                throw new \RuntimeException(\sprintf('cannot remove "%s" after copying it', $from));
            }
        }
    }

    /** Removes the folders the import left empty, up to (not including) the import root. */
    private function pruneEmptyDirs(string $dir, string $root): void
    {
        $root = rtrim($root, '/');
        while (str_starts_with($dir, $root . '/') && is_dir($dir)) {
            $entries = array_diff(scandir($dir) ?: [], ['.', '..', '.DS_Store', 'Thumbs.db', 'desktop.ini']);
            if ($entries !== []) {
                return;
            }
            foreach (['.DS_Store', 'Thumbs.db', 'desktop.ini'] as $junk) {
                @unlink($dir . '/' . $junk);
            }
            if (!@rmdir($dir)) {
                return;
            }
            $dir = \dirname($dir);
        }
    }
}
