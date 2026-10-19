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
 *
 * getID3 replaces the whole tag on write (`overwrite_tags=false` is
 * unsupported), so existing fields are read first and merged: a partial
 * update no longer wipes the year, track number, cover or POPM star rating.
 */
class MetadataWriter
{
    /** ID3v2 text fields carried over from the existing tag on a partial write. */
    private const ID3V2_KEPT_FIELDS = [
        'title', 'artist', 'album', 'band', 'year', 'track_number', 'part_of_a_set', 'genre', 'comment',
        'composer', 'publisher', 'copyright_message', 'encoded_by', 'bpm', 'unsynchronised_lyric',
        'original_artist', 'conductor', 'initial_key', 'language', 'content_group_description', 'subtitle',
        'url_file', // WOAF: the open.spotify.com link written by app:spotify:match
    ];

    /** Vorbis comment names carried as binary picture blocks, rewritten separately. */
    private const VORBIS_SKIPPED_FIELDS = ['metadata_block_picture', 'coverart', 'coverartmime'];

    public function __construct(
        private readonly string $getid3Dir,
    ) {}

    /**
     * Writes title/artist/album/genre/lyrics (and optionally a cover image)
     * into the file's native tag format, keeping every other existing tag.
     *
     * @param string|null $coverJpeg raw JPEG bytes for the embedded cover, or null to leave artwork untouched
     *
     * @return bool true if at least one tag format was written successfully
     */
    public function write(Music $song, string $title, string $artist, string $album, string $genre, string $lyrics, ?string $coverJpeg = null): bool
    {
        return $this->writeTags((string) $song->getFile(), [
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'genre' => $genre,
            'lyrics' => $lyrics,
        ], $coverJpeg);
    }

    /**
     * Updates only the given fields, merged with the tags already in the file.
     *
     * Keys: title, artist, album, albumArtist, genre, year, trackNumber,
     * trackTotal, discNumber, discTotal, lyrics. A key that is absent is left
     * as is; null or '' removes the field.
     *
     * @param array<string, string|int|null> $fields
     * @param string|null $cover raw JPEG/PNG bytes for the front cover, or null to keep the current one
     *
     * @return bool true if at least one tag format was written successfully
     */
    public function writeTags(string $file, array $fields, ?string $cover = null): bool
    {
        if (!is_file($file) || !$this->loadWriter()) {
            return false;
        }

        $ext = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        $vorbis = \in_array($ext, ['flac', 'ogg'], true);

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file);
        \getid3_lib::CopyTagsToComments($info);

        $data = $vorbis ? $this->existingVorbis($info) : $this->existingId3v2($info);
        foreach ($this->mapFields($fields, $vorbis) as $key => $value) {
            if ($value === null || $value === '') {
                unset($data[$key]);
            } else {
                $data[$key] = [(string) $value];
            }
        }

        $picture = $cover ?? ($info['comments']['picture'][0]['data'] ?? null);
        if (\is_string($picture) && $picture !== '') {
            $data['attached_picture'] = [[
                'data' => $picture,
                'picturetypeid' => 3, // Cover (front)
                'description' => 'Cover',
                'mime' => $this->imageMime($picture),
            ]];
        }

        // WMP keeps its star rating in a POPM frame (see RatingReader).
        $popm = $info['id3v2']['POPM'][0] ?? null;
        if (!$vorbis && \is_array($popm) && isset($popm['email'], $popm['rating'])) {
            $data['popularimeter'] = ['email' => $popm['email'], 'rating' => $popm['rating'], 'data' => $popm['counter'] ?? 0];
        }

        $writer = new \getid3_writetags();
        $writer->filename = $file;
        $writer->tagformats = $this->tagFormatsFor($ext);
        $writer->overwrite_tags = true;
        $writer->tag_encoding = 'UTF-8';
        $writer->remove_other_tags = false;
        $writer->tag_data = $data;

        $ok = $writer->WriteTags();
        if (!$ok) {
            // Fallback: an already-corrupted tag frame in the physical file can
            // make a merge write fail. Wipe existing tags and rewrite clean
            // (the merged data above still carries the previous values).
            $writer->remove_other_tags = true;
            $ok = $writer->WriteTags();
        }

        clearstatcache(true, $file);

        return $ok;
    }

    /**
     * write.php asserts the GETID3_INCLUDEPATH constant is already defined,
     * which only happens once getid3.php has actually run (not merely once
     * the `getID3` class is classmapped). Referencing the class forces
     * composer's classmap autoloader to include it first.
     */
    private function loadWriter(): bool
    {
        class_exists(\getID3::class);

        if (!class_exists(\getid3_writetags::class)) {
            $writeScript = $this->getid3Dir . '/write.php';
            if (!is_file($writeScript)) {
                return false;
            }
            require_once $writeScript;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, list<string>>
     */
    private function existingId3v2(array $info): array
    {
        $data = [];
        foreach (self::ID3V2_KEPT_FIELDS as $key) {
            $values = $info['tags']['id3v2'][$key] ?? $info['tags']['id3v1'][$key] ?? null;
            if ($key === 'track_number' && $values === null) {
                $values = $info['tags']['id3v1']['track'] ?? null;
            }
            $values = $this->stringValues($values);
            if ($values !== []) {
                $data[$key] = $values;
            }
        }

        // getID3 splits TRCK "7/12" into track_number + totaltracks on read.
        $total = $this->stringValues($info['tags']['id3v2']['totaltracks'] ?? null)[0] ?? null;
        if ($total !== null && isset($data['track_number'][0]) && !str_contains($data['track_number'][0], '/')) {
            $data['track_number'][0] .= '/' . $total;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, list<string>>
     */
    private function existingVorbis(array $info): array
    {
        $data = [];
        foreach ($info['tags']['vorbiscomment'] ?? [] as $key => $values) {
            $key = strtolower((string) $key);
            if (\in_array($key, self::VORBIS_SKIPPED_FIELDS, true)) {
                continue;
            }
            $values = $this->stringValues($values);
            if ($values !== []) {
                $data[$key] = $values;
            }
        }

        return $data;
    }

    /**
     * Semantic field names → tag keys of the target format.
     *
     * @param array<string, string|int|null> $fields
     *
     * @return array<string, string|int|null>
     */
    private function mapFields(array $fields, bool $vorbis): array
    {
        $mapped = [];
        $simple = [
            'title' => 'title',
            'artist' => 'artist',
            'album' => 'album',
            'genre' => 'genre',
            'lyrics' => 'unsynchronised_lyric',
            'albumArtist' => $vorbis ? 'albumartist' : 'band',
            'year' => $vorbis ? 'date' : 'year',
        ];
        foreach ($simple as $field => $key) {
            if (\array_key_exists($field, $fields)) {
                $mapped[$key] = $fields[$field];
            }
        }

        foreach (['track' => ['tracknumber', 'tracktotal', 'track_number'], 'disc' => ['discnumber', 'disctotal', 'part_of_a_set']] as $prefix => [$vNumber, $vTotal, $id3Key]) {
            if (!\array_key_exists($prefix . 'Number', $fields)) {
                continue;
            }
            $number = $fields[$prefix . 'Number'];
            $total = $fields[$prefix . 'Total'] ?? null;
            if ($vorbis) {
                $mapped[$vNumber] = $number;
                $mapped[$vTotal] = $number !== null && $number !== '' ? $total : null;
            } else {
                $mapped[$id3Key] = $number === null || $number === '' ? null : $number . ($total ? '/' . $total : '');
            }
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    private function stringValues(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $v): ?string => \is_scalar($v) ? trim((string) $v) : null, $values),
            static fn(?string $v): bool => $v !== null && $v !== '',
        ));
    }

    private function imageMime(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
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
