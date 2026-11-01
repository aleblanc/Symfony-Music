<?php

namespace App\Service;

use App\Entity\Music;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cover art as files: public/images/cover_cache/<sha1>.webp, where the sha1 is
 * music.cover_hash (hash of the cover bytes in the tags). The web server serves
 * an existing file directly; a missing one falls through to Symfony
 * (StreamController::cachedCover), which extracts the cover from the audio file,
 * writes it here and returns it. Same image, same hash: the tracks of an album
 * share one file, and a new cover gets a new URL (cacheable forever). The
 * directory can be deleted at any time.
 */
class CoverCache
{
    /** Sidecar images tried when the tags carry no picture. */
    public const COVER_CANDIDATES = ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'artwork.jpg'];

    private readonly string $dir;

    public function __construct(
        private readonly CoverImageProcessor $images,
        private readonly UrlGeneratorInterface $urls,
        string $projectDir,
    ) {
        $this->dir = $projectDir . '/public/images/cover_cache';
    }

    public static function hash(string $bytes): string
    {
        return sha1($bytes);
    }

    /**
     * Cover bytes from parsed tags (getID3 comments), else from an image beside
     * the file: "<track>.jpg", then cover.jpg, folder.jpg…
     *
     * @param array<string, mixed> $comments
     */
    public static function fromTags(array $comments, string $filePath): ?string
    {
        $raw = $comments['picture'][0]['data'] ?? null;
        if (\is_string($raw) && $raw !== '') {
            return $raw;
        }

        $dir = pathinfo($filePath, \PATHINFO_DIRNAME);
        $base = pathinfo($filePath, \PATHINFO_FILENAME);
        foreach ([$base . '.jpg', $base . '.png', ...self::COVER_CANDIDATES] as $candidate) {
            $path = $dir . '/' . $candidate;
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if (\is_string($raw) && $raw !== '') {
                    return $raw;
                }
            }
        }

        return null;
    }

    /** Cover bytes of an audio file (tags, else sidecar image); null if none or unreadable. */
    public function extract(string $filePath): ?string
    {
        if (!is_file($filePath) || !class_exists(\getID3::class)) {
            return null;
        }
        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($filePath);
        \getid3_lib::CopyTagsToComments($info);

        return self::fromTags($info['comments'] ?? [], $filePath);
    }

    public function url(string $hash): string
    {
        return $this->urls->generate('app_cover_cache', ['hash' => $hash]);
    }

    public function path(string $hash): string
    {
        return $this->dir . '/' . $hash . '.webp';
    }

    /**
     * The cached file of the track's cover, written first from the audio file
     * if missing. Null when the track has no cover or it cannot be read right
     * now (disk not mounted…).
     */
    public function file(Music $song): ?string
    {
        $hash = $song->getCoverHash();
        if ($hash === null) {
            return null;
        }
        $path = $this->path($hash);
        if (is_file($path)) {
            return $path;
        }

        $raw = $this->extract((string) $song->getFile());

        return $raw !== null ? $this->store($hash, $raw) : null;
    }

    /**
     * Writes $raw as the cached file of $hash (square WebP, 640 px), unless it
     * is already there. Written as .tmp then renamed: the web server never
     * serves a partial image. Returns the path, or null if it cannot be written.
     */
    public function store(string $hash, string $raw): ?string
    {
        $path = $this->path($hash);
        if (is_file($path)) {
            return $path;
        }
        $bytes = $this->images->toWebp($raw);
        if ($bytes === null) {
            return null;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return null;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
            @unlink($tmp);

            return null;
        }

        return $path;
    }
}
