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
 * unsupported). MP3 tags are therefore rebuilt from every existing frame by
 * {@see Id3TagRebuilder} (TXXX, UFID, WOAF Spotify link, POPM rating, extra
 * pictures...) with a backup and a lost-frame check; Vorbis comments are
 * merged key by key.
 */
class MetadataWriter
{
    /** Picard's conventions for MusicBrainz ids. */
    private const MB_UFID_OWNER = 'http://musicbrainz.org';
    private const MB_TXXX = [
        'musicbrainzReleaseId' => 'MusicBrainz Album Id',
        'musicbrainzReleaseGroupId' => 'MusicBrainz Release Group Id',
    ];
    private const MB_VORBIS = [
        'musicbrainzRecordingId' => 'musicbrainz_trackid',
        'musicbrainzReleaseId' => 'musicbrainz_albumid',
        'musicbrainzReleaseGroupId' => 'musicbrainz_releasegroupid',
    ];

    private ?string $lastError = null;

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
     * trackTotal, discNumber, discTotal, lyrics, musicbrainzRecordingId,
     * musicbrainzReleaseId, musicbrainzReleaseGroupId. A key that is absent is
     * left as is; null or '' removes the field.
     *
     * @param array<string, string|int|null> $fields
     * @param string|null $cover raw JPEG/PNG bytes for the front cover, null to keep the current one, '' to remove it
     *
     * @return bool true if the tags were written; see {@see lastError()} otherwise
     */
    public function writeTags(string $file, array $fields, ?string $cover = null): bool
    {
        $this->lastError = null;
        if (!is_file($file) || !$this->loadWriter()) {
            $this->lastError = 'file missing or getID3 write module unavailable';

            return false;
        }

        $ext = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        if ($ext === 'mp3') {
            return $this->writeId3($file, $fields, $cover);
        }
        $vorbis = \in_array($ext, ['flac', 'ogg'], true);

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file);
        \getid3_lib::CopyTagsToComments($info);

        $data = $vorbis ? $this->existingVorbis($info) : [];
        $mapped = $this->mapFields($fields, $vorbis);
        if ($vorbis) {
            foreach (self::MB_VORBIS as $field => $key) {
                if (\array_key_exists($field, $fields)) {
                    $mapped[$key] = $fields[$field];
                }
            }
        }
        foreach ($mapped as $key => $value) {
            if ($value === null || $value === '') {
                unset($data[$key]);
            } else {
                $data[$key] = [(string) $value];
            }
        }

        // null keeps the current cover, '' removes it.
        $picture = $cover ?? ($info['comments']['picture'][0]['data'] ?? null);
        if (\is_string($picture) && $picture !== '') {
            $data['attached_picture'] = [[
                'data' => $picture,
                'picturetypeid' => 3, // Cover (front)
                'description' => 'Cover',
                'mime' => $this->imageMime($picture),
            ]];
        }

        // getID3 hands Ogg files to `vorbiscomment` through shell_exec() with
        // the file name only wrapped in double quotes (write.vorbiscomment.php):
        // a downloaded "x$(cmd).ogg" would run cmd. Tags are written on a copy
        // with a safe temporary name, which then replaces the original.
        $target = $file;
        $workCopy = null;
        if ($ext === 'ogg') {
            $workCopy = tempnam(sys_get_temp_dir(), 'tagw');
            if ($workCopy === false || !rename($workCopy, $workCopy . '.ogg') || !copy($file, $workCopy . '.ogg')) {
                $this->lastError = 'could not create a working copy for tag writing';

                return false;
            }
            $workCopy .= '.ogg';
            $target = $workCopy;
        }

        $writer = new \getid3_writetags();
        $writer->filename = $target;
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

        if ($workCopy !== null) {
            if ($ok && !copy($workCopy, $file)) {
                $ok = false;
                $writer->errors[] = 'could not replace the original file';
            }
            @unlink($workCopy);
        }

        clearstatcache(true, $file);
        if (!$ok) {
            $this->lastError = 'getID3: ' . implode('; ', $writer->errors);
        }

        return $ok;
    }

    /** Why the last {@see writeTags()} call failed, null after a success. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<string, string|int|null> $fields
     */
    private function writeId3(string $file, array $fields, ?string $cover): bool
    {
        $rebuilder = new Id3TagRebuilder($this->getid3Dir);

        try {
            $info = $rebuilder->analyze($file);
            $data = $rebuilder->rebuild($info);
            $changed = [];

            foreach ($this->mapFields($fields, false) as $key => $value) {
                $changed[] = $rebuilder->frameId($key);
                if ($value === null || $value === '') {
                    unset($data[$key]);
                } else {
                    $data[$key] = [(string) $value];
                }
            }

            if (\array_key_exists('musicbrainzRecordingId', $fields)) {
                $changed[] = 'UFID';
                $id = (string) $fields['musicbrainzRecordingId'];
                if ($id === '') {
                    unset($data['unique_file_identifier']);
                } else {
                    $data['unique_file_identifier'] = ['ownerid' => self::MB_UFID_OWNER, 'data' => $id];
                }
            }
            foreach (self::MB_TXXX as $field => $description) {
                if (!\array_key_exists($field, $fields)) {
                    continue;
                }
                $changed[] = 'TXXX';
                $texts = array_values(array_filter($data['text'] ?? [], static fn(array $t): bool => ($t['description'] ?? '') !== $description));
                if ((string) $fields[$field] !== '') {
                    $texts[] = ['description' => $description, 'data' => (string) $fields[$field]];
                }
                $data['text'] = $texts;
                if ($texts === []) {
                    unset($data['text']);
                }
            }

            // null keeps the pictures; otherwise only the front cover is
            // replaced ('' removes it), back covers & co. stay.
            if ($cover !== null) {
                $changed[] = 'APIC';
                $pictures = array_values(array_filter($data['attached_picture'] ?? [], static fn(array $p): bool => (int) ($p['picturetypeid'] ?? 3) !== 3));
                if ($cover !== '') {
                    array_unshift($pictures, ['data' => $cover, 'picturetypeid' => 3, 'description' => 'Cover', 'mime' => $this->imageMime($cover)]);
                }
                $data['attached_picture'] = $pictures;
                if ($pictures === []) {
                    unset($data['attached_picture']);
                }
            }

            $rebuilder->write($file, $info, $data, array_values(array_unique(array_filter($changed))));
        } catch (\RuntimeException $e) {
            $this->lastError = $e->getMessage();

            return false;
        }

        clearstatcache(true, $file);

        return true;
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
