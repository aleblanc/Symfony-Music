<?php

namespace App\Service;

use App\Entity\Music;

/**
 * Writes ID3/Vorbis/FLAC/MP4 tags to a physical audio file. Ported from the
 * tag-writing block of the legacy `edit_metadata` / `save_audio_editor`
 * actions, which loaded `lib/getid3/write.php` on demand and picked a
 * `$tagformats` list based on the file extension.
 *
 * The `write.php` module is not part of the composer classmap (only
 * `getid3.php` is), so it is required lazily here, exactly like the legacy
 * code did with `require_once __DIR__ . '/getid3/write.php';`.
 */
class MetadataWriter
{
    public function __construct(
        private readonly string $getid3Dir,
    ) {}

    /**
     * Writes title/artist/album/genre/lyrics (and optionally a cover image)
     * into the file's native tag format.
     *
     * @param string|null $coverJpeg raw JPEG bytes for the embedded cover, or null to leave artwork untouched
     *
     * @return bool true if at least one tag format was written successfully
     */
    public function write(Music $song, string $title, string $artist, string $album, string $genre, string $lyrics, ?string $coverJpeg = null): bool
    {
        $file = (string) $song->getFile();
        if (!is_file($file)) {
            return false;
        }

        // write.php asserts the GETID3_INCLUDEPATH constant is already
        // defined, which only happens once getid3.php has actually run (not
        // merely once the `getID3` class is classmapped). Referencing the
        // class here forces composer's classmap autoloader to include it if
        // this is the first use in the request.
        class_exists(\getID3::class);

        if (!class_exists(\getid3_writetags::class)) {
            $writeScript = $this->getid3Dir . '/write.php';
            if (!is_file($writeScript)) {
                return false;
            }
            require_once $writeScript;
        }

        $ext = strtolower(pathinfo($file, \PATHINFO_EXTENSION));

        $writer = new \getid3_writetags();
        $writer->filename = $file;
        $writer->tagformats = $this->tagFormatsFor($ext);
        $writer->overwrite_tags = true;
        $writer->tag_encoding = 'UTF-8';
        $writer->remove_other_tags = false;
        $writer->tag_data = [
            'title' => [$title],
            'artist' => [$artist],
            'album' => [$album],
            'genre' => [$genre],
            'unsynchronised_lyric' => [$lyrics],
        ];

        if ($coverJpeg !== null && $coverJpeg !== '') {
            $writer->tag_data['attached_picture'] = [[
                'data' => $coverJpeg,
                'picturetypeid' => 3, // Cover (front)
                'description' => 'Cover',
                'mime' => 'image/jpeg',
            ]];
        }

        $ok = $writer->WriteTags();
        if (!$ok) {
            // Fallback: an already-corrupted tag frame in the physical file can
            // make a merge write fail. Wipe existing tags and rewrite clean.
            $writer->remove_other_tags = true;
            $ok = $writer->WriteTags();
        }

        clearstatcache(true, $file);

        return $ok;
    }

    /**
     * @return string[]
     */
    private function tagFormatsFor(string $extension): array
    {
        return match ($extension) {
            'mp3' => ['id3v1', 'id3v2.3'],
            'flac' => ['metaflac'],
            'ogg' => ['vorbiscomment'],
            'm4a' => ['mp4'],
            default => ['id3v2.3'],
        };
    }
}
