<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use App\Entity\MetadataSuggestion;
use App\Entity\Music;
use App\Enum\SuggestionStatus;
use App\Service\CoverImageProcessor;
use App\Service\MetadataWriter;

/**
 * Applies a {@see MetadataSuggestion} to the audio file and the `music` row,
 * or reverts an applied one. The file is written first: when that fails the
 * entity is left untouched and the suggestion stays pending with the error.
 *
 * Does not flush: callers own the unit of work.
 */
class SuggestionApplier
{
    /** Formats getID3 can write tags into (MP4 and WAV are database-only). */
    private const WRITABLE_EXTENSIONS = ['mp3', 'flac', 'ogg'];

    public function __construct(
        private readonly MetadataWriter $writer,
        private readonly CoverImageProcessor $images,
    ) {}

    public function apply(MetadataSuggestion $suggestion): bool
    {
        $music = $suggestion->getMusic();
        $fields = array_intersect_key($suggestion->getProposed(), array_flip(MetadataSuggestion::FIELDS));
        $cover = $suggestion->getCover();

        $previous = $this->snapshot($music);
        $previousCover = $cover !== null ? $this->imageBytes($music) : null;

        if (!$this->writeFile($music, $fields, $cover !== null ? ($this->images->toJpeg($cover) ?? $cover) : null)) {
            $suggestion->markFailed(\sprintf('Could not write tags to "%s".', $music->getFile()));

            return false;
        }

        $this->applyToEntity($music, $fields);
        if ($cover !== null) {
            $music->setImage($this->images->toWebp($cover) ?? $cover);
        }
        $this->touch($music);
        $suggestion->markApplied($previous, $previousCover);

        return true;
    }

    public function revert(MetadataSuggestion $suggestion): bool
    {
        if ($suggestion->getStatus() !== SuggestionStatus::Applied) {
            return false;
        }

        $music = $suggestion->getMusic();
        $previous = array_intersect_key($suggestion->getPrevious() ?? [], array_flip(MetadataSuggestion::FIELDS));
        $fields = $previous + ['trackTotal' => null, 'discTotal' => null];

        $cover = null;
        if ($suggestion->hasCover()) {
            $previousCover = $suggestion->getPreviousCover();
            // '' tells the writer to drop the cover that was added.
            $cover = $previousCover !== null ? ($this->images->toJpeg($previousCover) ?? $previousCover) : '';
        }

        if (!$this->writeFile($music, $fields, $cover)) {
            return false;
        }

        $this->applyToEntity($music, $previous);
        if ($suggestion->hasCover()) {
            $music->setImage($suggestion->getPreviousCover());
        }
        $this->touch($music);
        $suggestion->markReverted();

        return true;
    }

    /**
     * @param array<string, string|int|null> $fields
     */
    private function writeFile(Music $music, array $fields, ?string $cover): bool
    {
        $file = (string) $music->getFile();
        if (!is_file($file)) {
            return false;
        }
        if (!\in_array(strtolower(pathinfo($file, \PATHINFO_EXTENSION)), self::WRITABLE_EXTENSIONS, true)) {
            return true;
        }

        return $this->writer->writeTags($file, $fields, $cover);
    }

    /**
     * @param array<string, string|int|null> $fields
     */
    private function applyToEntity(Music $music, array $fields): void
    {
        $string = static fn(string $key): ?string => isset($fields[$key]) && $fields[$key] !== '' ? (string) $fields[$key] : null;
        $int = static fn(string $key): ?int => isset($fields[$key]) && $fields[$key] !== '' ? (int) $fields[$key] : null;

        foreach ([
            'title' => static fn() => $music->setTitle($string('title')),
            'artist' => static fn() => $music->setArtist($string('artist')),
            'album' => static fn() => $music->setAlbum($string('album')),
            'albumArtist' => static fn() => $music->setAlbumArtist($string('albumArtist')),
            'genre' => static fn() => $music->setGenre($string('genre')),
            'year' => static fn() => $music->setYear($int('year')),
            'trackNumber' => static fn() => $music->setTrackNumber($int('trackNumber')),
            'discNumber' => static fn() => $music->setDiscNumber($int('discNumber')),
        ] as $key => $set) {
            if (\array_key_exists($key, $fields)) {
                $set();
            }
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    private function snapshot(Music $music): array
    {
        return [
            'title' => $music->getTitle(),
            'artist' => $music->getArtist(),
            'album' => $music->getAlbum(),
            'albumArtist' => $music->getAlbumArtist(),
            'genre' => $music->getGenre(),
            'year' => $music->getYear(),
            'trackNumber' => $music->getTrackNumber(),
            'discNumber' => $music->getDiscNumber(),
        ];
    }

    /** The file changed on purpose: record its mtime so `app:cron:scan` doesn't re-read it. */
    private function touch(Music $music): void
    {
        $file = (string) $music->getFile();
        clearstatcache(true, $file);
        $mtime = is_file($file) ? filemtime($file) : false;
        if ($mtime !== false) {
            $music->setLastModified($mtime);
        }
        $music->setEnrichedAt(new \DateTimeImmutable());
    }

    private function imageBytes(Music $music): ?string
    {
        $image = $music->getImage();
        if (\is_resource($image)) {
            rewind($image);
            $image = stream_get_contents($image);
        }

        return \is_string($image) && $image !== '' ? $image : null;
    }
}
