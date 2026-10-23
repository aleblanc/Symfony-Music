<?php

namespace App\Service\Import;

/**
 * Reads what an import-folder file says about itself (it is not indexed
 * yet), with the same fallbacks as the library scanner: a missing title
 * becomes the file name, which the hint extractor recognises as such.
 */
class AudioFileReader
{
    public const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'flac', 'ogg', 'wav'];

    /**
     * @return array{title: string, artist: ?string, album: ?string, genre: ?string, duration: int, trackNumber: ?int, hasImage: bool}
     */
    public function read(string $file): array
    {
        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file);
        \getid3_lib::CopyTagsToComments($info);
        $c = $info['comments'] ?? [];

        $first = static function (array $keys) use ($c): ?string {
            foreach ($keys as $key) {
                $value = $c[$key][0] ?? null;
                if (\is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            return null;
        };
        $track = $first(['track_number', 'tracknumber', 'track']);

        return [
            'title' => $first(['title']) ?? pathinfo($file, \PATHINFO_FILENAME),
            'artist' => $first(['artist']),
            'album' => $first(['album']),
            'genre' => $first(['genre']),
            'duration' => (int) round((float) ($info['playtime_seconds'] ?? 0)),
            'trackNumber' => $track !== null && preg_match('/^\s*(\d+)/', $track, $m) === 1 ? ((int) $m[1] ?: null) : null,
            'hasImage' => isset($c['picture'][0]['data']),
        ];
    }

    /**
     * Audio files under $root, grouped by folder (the duplicates folder and
     * hidden folders are skipped).
     *
     * @return array<string, list<string>> folder => files
     */
    public function listFiles(string $root): array
    {
        $root = rtrim($root, '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            static function (\SplFileInfo $f) use ($root): bool {
                if ($f->isDir()) {
                    // Duplicates and the FLAC originals kept by --flac-to-aac are not imported again.
                    return !str_starts_with($f->getFilename(), '.')
                        && !\in_array($f->getPathname(), [$root . '/' . FileImporter::DUPLICATES_DIR, $root . '/' . FlacConverter::ORIGINALS_DIR], true);
                }

                return !str_starts_with($f->getFilename(), '.')
                    && \in_array(strtolower($f->getExtension()), self::AUDIO_EXTENSIONS, true);
            },
        ));

        $folders = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $folders[$file->getPath()][] = $file->getPathname();
        }
        ksort($folders);
        foreach ($folders as &$files) {
            sort($files);
        }

        return $folders;
    }
}
