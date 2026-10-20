<?php

namespace App\Service;

use App\Entity\Music;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

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
 *
 * getID3 cannot write MP4 (M4A) tags: those go through the `AtomicParsley`
 * binary (`brew install atomicparsley` / `apt install atomicparsley`), which
 * only touches the atoms it is given.
 */
class MetadataWriter
{
    /** Picard's conventions for MusicBrainz ids. */
    private const MB_UFID_OWNER = 'http://musicbrainz.org';
    private const MB_TXXX = [
        'musicbrainzReleaseId' => 'MusicBrainz Album Id',
        'musicbrainzReleaseGroupId' => 'MusicBrainz Release Group Id',
    ];
    /** Picard's MP4 names, as "----:com.apple.iTunes:<name>" atoms. */
    private const MB_MP4 = [
        'musicbrainzRecordingId' => 'MusicBrainz Track Id',
        'musicbrainzReleaseId' => 'MusicBrainz Album Id',
        'musicbrainzReleaseGroupId' => 'MusicBrainz Release Group Id',
    ];
    private const MP4_EXTENSIONS = ['m4a', 'mp4', 'm4b'];
    private const MB_VORBIS = [
        'musicbrainzRecordingId' => 'musicbrainz_trackid',
        'musicbrainzReleaseId' => 'musicbrainz_albumid',
        'musicbrainzReleaseGroupId' => 'musicbrainz_releasegroupid',
    ];

    private ?string $lastError = null;

    /** Vorbis comment names carried as binary picture blocks, rewritten separately. */
    private const VORBIS_SKIPPED_FIELDS = ['metadata_block_picture', 'coverart', 'coverartmime'];

    private string|false|null $atomicParsley = false;

    public function __construct(
        private readonly string $getid3Dir,
        private readonly string $atomicParsleyPath = '',
    ) {}

    /** Whether tags can be written into files of this extension on this machine. */
    public function canWrite(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));
        if (\in_array($ext, self::MP4_EXTENSIONS, true)) {
            return $this->atomicParsleyBinary() !== null;
        }

        return \in_array($ext, ['mp3', 'flac', 'ogg'], true);
    }

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

        // The content decides, not the extension: AAC bought on iTunes is
        // sometimes renamed .mp3.
        $ext = $this->detectFormat($file) ?? strtolower(pathinfo($file, \PATHINFO_EXTENSION));
        if ($ext === 'mp3') {
            return $this->writeId3($file, $fields, $cover);
        }
        if (\in_array($ext, self::MP4_EXTENSIONS, true)) {
            return $this->writeMp4($file, $fields, $cover);
        }
        $vorbis = \in_array($ext, ['flac', 'ogg'], true);
        if ($vorbis && !$this->prepareVorbisTool($ext)) {
            return false;
        }

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

    /**
     * Audio format of the file's content (mp3, m4a, flac, ogg, wav), null when
     * getID3 does not recognise it.
     */
    public function detectFormat(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        class_exists(\getID3::class);
        $info = (new \getID3())->analyze($file);

        return match ($info['fileformat'] ?? null) {
            'mp3' => 'mp3',
            'mp4', 'quicktime' => 'm4a',
            'flac' => 'flac',
            'ogg' => 'ogg',
            'riff', 'wav' => 'wav',
            default => null,
        };
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
            // The low-level writer would happily prepend a tag to anything.
            if (($info['audio']['dataformat'] ?? null) !== 'mp3') {
                throw new \RuntimeException('not an MP3 audio stream');
            }
            $major = $rebuilder->majorVersion($info);
            $frames = $rebuilder->frames($info);
            $changed = [];
            $set = static function (string $id, ?string $value) use (&$frames, &$changed, $rebuilder, $major): void {
                $changed[] = $id;
                unset($frames[$id]);
                if ($value !== null && $value !== '') {
                    $frames[$id] = [$rebuilder->text($value, $major)];
                }
            };
            $value = static fn(string $key): ?string => isset($fields[$key]) && (string) $fields[$key] !== '' ? (string) $fields[$key] : null;

            foreach (['title' => 'TIT2', 'artist' => 'TPE1', 'album' => 'TALB', 'albumArtist' => 'TPE2', 'genre' => 'TCON'] as $field => $id) {
                if (\array_key_exists($field, $fields)) {
                    $set($id, $value($field));
                }
            }
            if (\array_key_exists('year', $fields)) {
                // 2.4 dates live in TDRC, 2.3 years in TYER (+ TDAT/TIME, dropped with it).
                foreach (['TYER', 'TDAT', 'TIME', 'TDRC'] as $id) {
                    $set($id, null);
                }
                $set($major === 4 ? 'TDRC' : 'TYER', $value('year'));
            }
            foreach (['track' => 'TRCK', 'disc' => 'TPOS'] as $prefix => $id) {
                if (\array_key_exists($prefix . 'Number', $fields)) {
                    $number = $value($prefix . 'Number');
                    $total = $value($prefix . 'Total');
                    $set($id, $number !== null ? $number . ($total !== null ? '/' . $total : '') : null);
                }
            }
            if (\array_key_exists('lyrics', $fields)) {
                $changed[] = 'USLT';
                unset($frames['USLT']);
                if ($value('lyrics') !== null) {
                    $text = $rebuilder->text((string) $value('lyrics'), $major);
                    $frames['USLT'] = [$text + ['language' => 'eng', 'description' => '']];
                }
            }

            // MusicBrainz ids, Picard-style: recording in UFID, release ids in TXXX.
            if (\array_key_exists('musicbrainzRecordingId', $fields)) {
                $changed[] = 'UFID';
                $frames['UFID'] = array_values(array_filter($frames['UFID'] ?? [], static fn(array $f): bool => ($f['ownerid'] ?? '') !== self::MB_UFID_OWNER));
                if ($value('musicbrainzRecordingId') !== null) {
                    $frames['UFID'][] = ['ownerid' => self::MB_UFID_OWNER, 'data' => (string) $value('musicbrainzRecordingId')];
                }
            }
            foreach (self::MB_TXXX as $field => $description) {
                if (!\array_key_exists($field, $fields)) {
                    continue;
                }
                $changed[] = 'TXXX';
                $frames['TXXX'] = array_values(array_filter($frames['TXXX'] ?? [], fn(array $f): bool => $this->decodeText($f, 'description') !== $description));
                if ($value($field) !== null) {
                    $text = $rebuilder->text((string) $value($field), $major);
                    $frames['TXXX'][] = ['encodingid' => $text['encodingid'], 'description' => $rebuilder->text($description, $major)['data'] ?? $description, 'data' => $text['data']];
                }
            }

            // null keeps the pictures; otherwise only the front cover is
            // replaced ('' removes it), back covers & co. stay.
            if ($cover !== null) {
                $changed[] = 'APIC';
                $frames['APIC'] = array_values(array_filter($frames['APIC'] ?? [], static fn(array $p): bool => (int) ($p['picturetypeid'] ?? 3) !== 3));
                if ($cover !== '') {
                    array_unshift($frames['APIC'], ['encodingid' => 0, 'mime' => $this->imageMime($cover), 'picturetypeid' => 3, 'description' => 'Cover', 'data' => $cover]);
                }
            }
            $frames = array_filter($frames, static fn(array $list): bool => $list !== []);

            // ID3v1 (when present) mirrors the new values.
            $comments = $info['comments'] ?? [];
            $current = static fn(string $key): string => \is_scalar($comments[$key][0] ?? null) ? (string) $comments[$key][0] : '';
            $id3v1 = [
                'title' => \array_key_exists('title', $fields) ? (string) $value('title') : $current('title'),
                'artist' => \array_key_exists('artist', $fields) ? (string) $value('artist') : $current('artist'),
                'album' => \array_key_exists('album', $fields) ? (string) $value('album') : $current('album'),
                'year' => \array_key_exists('year', $fields) ? (string) $value('year') : substr($current('year'), 0, 4),
                'genre' => \array_key_exists('genre', $fields) ? (string) $value('genre') : $current('genre'),
                'track_number' => \array_key_exists('trackNumber', $fields) ? (string) $value('trackNumber') : $current('track_number'),
                'comment' => $current('comment'),
            ];

            $rebuilder->write($file, $info, $frames, array_values(array_unique($changed)), $id3v1);
        } catch (\RuntimeException $e) {
            $this->lastError = $e->getMessage();

            return false;
        }

        clearstatcache(true, $file);

        return true;
    }

    /**
     * A text field of a raw ID3v2 frame, decoded to UTF-8 (raw frames keep
     * their original encoding: ISO-8859-1, UTF-16 with BOM, UTF-16BE, UTF-8).
     *
     * @param array<string, mixed> $frame
     */
    private function decodeText(array $frame, string $key): string
    {
        $raw = (string) ($frame[$key] ?? '');
        $from = match ((int) ($frame['encodingid'] ?? 0)) {
            1 => 'UTF-16',
            2 => 'UTF-16BE',
            3 => 'UTF-8',
            default => 'ISO-8859-1',
        };

        return $from === 'UTF-8' ? $raw : (string) @iconv($from, 'UTF-8//IGNORE', $raw);
    }

    /**
     * MP4 atoms through AtomicParsley. The new file is written next to the
     * original, checked with getID3, then renamed over it: a failure never
     * leaves a half-written file behind.
     *
     * @param array<string, string|int|null> $fields
     */
    private function writeMp4(string $file, array $fields, ?string $cover): bool
    {
        $binary = $this->atomicParsleyBinary();
        if ($binary === null) {
            $this->lastError = 'AtomicParsley not found: install it (brew install atomicparsley / apt install atomicparsley) to write M4A tags';

            return false;
        }

        $args = [];
        $options = [
            'title' => '--title',
            'artist' => '--artist',
            'album' => '--album',
            'albumArtist' => '--albumArtist',
            'genre' => '--genre',
            'year' => '--year',
            'lyrics' => '--lyrics',
        ];
        foreach ($options as $field => $option) {
            // '' deletes the atom.
            if (\array_key_exists($field, $fields)) {
                array_push($args, $option, trim((string) $fields[$field]));
            }
        }
        foreach (['track' => '--tracknum', 'disc' => '--disk'] as $prefix => $option) {
            if (!\array_key_exists($prefix . 'Number', $fields)) {
                continue;
            }
            $number = (int) $fields[$prefix . 'Number'];
            $total = (int) ($fields[$prefix . 'Total'] ?? 0);
            if ($number > 0) {
                array_push($args, $option, $number . ($total > 0 ? '/' . $total : ''));
            } else {
                $atom = $prefix === 'track' ? 'trkn' : 'disk';
                array_push($args, '--manualAtomRemove', 'moov.udta.meta.ilst.' . $atom);
            }
        }
        foreach (self::MB_MP4 as $field => $name) {
            if (\array_key_exists($field, $fields)) {
                array_push($args, '--rDNSatom', (string) $fields[$field], 'name=' . $name, 'domain=com.apple.iTunes');
            }
        }

        $dir = \dirname($file);
        $coverFile = null;
        if ($cover !== null) {
            array_push($args, '--artwork', 'REMOVE_ALL');
            if ($cover !== '') {
                $coverFile = $this->tempPath($dir, $this->imageMime($cover) === 'image/png' ? '.png' : '.jpg');
                file_put_contents($coverFile, $cover);
                array_push($args, '--artwork', $coverFile);
            }
        }
        if ($args === []) {
            return true;
        }

        $output = $this->tempPath($dir, '.' . pathinfo($file, \PATHINFO_EXTENSION));
        $process = new Process([$binary, $file, ...$args, '--output', $output]);
        $process->setTimeout(120);

        try {
            try {
                $process->run();
            } catch (ProcessException $e) {
                $this->lastError = 'AtomicParsley: ' . $e->getMessage();

                return false;
            }
            if (!$process->isSuccessful() || !is_file($output) || filesize($output) === 0) {
                $this->lastError = 'AtomicParsley: ' . trim($process->getErrorOutput() . ' ' . $process->getOutput());

                return false;
            }

            // The rewritten file must still be a playable MP4 of the same length.
            $getID3 = new \getID3();
            $before = $getID3->analyze($file);
            $after = $getID3->analyze($output);
            if (($after['fileformat'] ?? null) !== 'mp4' || abs((float) ($after['playtime_seconds'] ?? 0) - (float) ($before['playtime_seconds'] ?? 0)) > 0.5) {
                $this->lastError = 'AtomicParsley produced an unreadable file; original kept';

                return false;
            }
            if (!rename($output, $file)) {
                $this->lastError = 'could not replace the original file';

                return false;
            }
        } finally {
            if ($coverFile !== null) {
                @unlink($coverFile);
            }
            if (is_file($output)) {
                @unlink($output);
            }
            clearstatcache(true, $file);
        }

        return true;
    }

    /**
     * getID3 runs `metaflac` (FLAC) / `vorbiscomment` (Ogg) from the PATH,
     * which a web server's PATH may lack (Homebrew): locate the tool, put its
     * folder on the PATH, or explain what to install.
     */
    private function prepareVorbisTool(string $ext): bool
    {
        [$tool, $package] = $ext === 'flac' ? ['metaflac', 'flac'] : ['vorbiscomment', 'vorbis-tools'];
        $binary = (new BinaryLocator())->find($tool);
        if ($binary === null) {
            $this->lastError = \sprintf('%s not found: install it to write %s tags (brew install %s / apt install %s)', $tool, strtoupper($ext), $package, $package);

            return false;
        }
        $dir = \dirname($binary);
        $path = (string) getenv('PATH');
        if (!\in_array($dir, explode(\PATH_SEPARATOR, $path), true)) {
            putenv('PATH=' . $dir . ($path !== '' ? \PATH_SEPARATOR . $path : ''));
        }

        return true;
    }

    private function atomicParsleyBinary(): ?string
    {
        if ($this->atomicParsley === false) {
            $this->atomicParsley = (new BinaryLocator())->find('AtomicParsley', $this->atomicParsleyPath)
                ?? (new BinaryLocator())->find('atomicparsley');
        }

        return $this->atomicParsley;
    }

    /** A hidden, unused file name in $dir (same file system: rename() stays atomic). */
    private function tempPath(string $dir, string $suffix): string
    {
        return $dir . '/.tagw-' . bin2hex(random_bytes(6)) . $suffix;
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
