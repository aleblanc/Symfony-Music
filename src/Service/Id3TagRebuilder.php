<?php

namespace App\Service;

/**
 * Rewrites an MP3's ID3v2 tag without losing frames.
 *
 * The tag is rebuilt frame by frame from what getID3 parsed — byte-for-byte
 * (each text keeps its original encoding), in the file's own ID3v2 version
 * (2.4 stays 2.4: TDRC...) — then only the requested frames are replaced.
 * The low-level writer (getid3_write_id3v2) is used directly: the high-level
 * getid3_writetags turned PRIV, multi-language COMM, WXXX or GEOB into plain
 * text or refused them.
 *
 * The file is backed up first and every frame type not deliberately changed
 * must come back with the same count; otherwise the backup is restored.
 */
class Id3TagRebuilder
{
    /**
     * Technical frames whose loss is accepted when getID3 cannot write them
     * back: CD table of contents (MCDI), MusicMatch data (NCON), play counter,
     * buffer/seek/audio-seek-point indexes, signatures, encryption, grouping
     * and linked-information registrations, position sync.
     */
    private const TECHNICAL_FRAMES = ['MCDI', 'NCON', 'PCNT', 'RBUF', 'SEEK', 'ASPI', 'SIGN', 'ENCR', 'GRID', 'AENC', 'LINK', 'POSS'];

    /** Frames that may legitimately repeat (merged across stacked tags); the others keep the first tag's. */
    private const MULTI_FRAMES = ['APIC', 'PRIV', 'TXXX', 'COMM', 'USLT', 'UFID', 'WXXX', 'GEOB', 'POPM'];

    public function __construct(
        private readonly string $getid3Dir,
    ) {}

    /**
     * @return array<string, mixed> getID3 analysis with tags copied to comments
     */
    public function analyze(string $file): array
    {
        $this->loadWriter();
        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file);
        \getid3_lib::CopyTagsToComments($info);

        return $info;
    }

    /**
     * ID3v2 version to write: the file's own (3 or 4); 2.2 and untagged files get 2.3.
     *
     * @param array<string, mixed> $info
     */
    public function majorVersion(array $info): int
    {
        return (int) ($info['id3v2']['majorversion'] ?? 3) === 4 ? 4 : 3;
    }

    /**
     * The current ID3v2 frames, in getid3_write_id3v2::$tag_data form.
     *
     * @param array<string, mixed> $info
     *
     * @return array<string, list<array<string, mixed>>> frame id => frames
     */
    public function frames(array $info): array
    {
        $frames = [];
        // ID3v2.2 frame ids have 3 letters: not reproducible as such.
        if ((int) ($info['id3v2']['majorversion'] ?? 3) < 3) {
            return $frames;
        }
        foreach (\is_array($info['id3v2'] ?? null) ? $info['id3v2'] : [] as $id => $list) {
            if (!\is_string($id) || preg_match('/^[A-Z][A-Z0-9]{3}$/', $id) !== 1 || !\is_array($list)) {
                continue;
            }
            foreach ($list as $frame) {
                if (\is_array($frame)) {
                    $frames[$id][] = $this->writable($id, $frame);
                }
            }
        }

        return $frames;
    }

    /**
     * Several ID3v2 tags stacked at the start of the file (an ID3v2.3 tag,
     * then an ID3v2.4 one, then the audio — seen on downloads): getID3 reads
     * the first only and looks for the audio right after it. Returns the
     * frames of all tags merged (the first tag wins, the next ones complete
     * it), the first tag's version, and where the audio really starts; null
     * when there is at most one tag.
     *
     * @return array{frames: array<string, list<array<string, mixed>>>, major: int, audioOffset: int}|null
     */
    public function stackedTags(string $file): ?array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $tags = [];
        $offset = 0;
        try {
            while (true) {
                fseek($handle, $offset);
                $header = (string) fread($handle, 10);
                if (\strlen($header) < 10 || !str_starts_with($header, 'ID3')) {
                    break;
                }
                $size = ((\ord($header[6]) & 0x7F) << 21) | ((\ord($header[7]) & 0x7F) << 14) | ((\ord($header[8]) & 0x7F) << 7) | (\ord($header[9]) & 0x7F);
                $length = 10 + $size + ((\ord($header[5]) & 0x10) !== 0 ? 10 : 0);
                $tags[] = [$offset, $length];
                $offset += $length;
                // Some taggers pad between tags with zero bytes.
                fseek($handle, $offset);
                $chunk = (string) fread($handle, 4096);
                $offset += \strlen($chunk) - \strlen(ltrim($chunk, "\x00"));
            }
            if (\count($tags) < 2) {
                return null;
            }

            $merged = [];
            $major = null;
            foreach ($tags as [$start, $length]) {
                // Each tag is parsed alone, followed by a little audio.
                fseek($handle, $start);
                $segment = (string) fread($handle, $length);
                fseek($handle, $offset);
                $segment .= (string) fread($handle, 65536);
                $tmp = tempnam(sys_get_temp_dir(), 'tagseg');
                if ($tmp === false) {
                    return null;
                }
                file_put_contents($tmp, $segment);
                $info = $this->analyze($tmp);
                @unlink($tmp);

                $major ??= $this->majorVersion($info);
                foreach ($this->frames($info) as $id => $list) {
                    if (!isset($merged[$id])) {
                        $merged[$id] = $list;
                    } elseif (\in_array($id, self::MULTI_FRAMES, true)) {
                        foreach ($list as $frame) {
                            if (!\in_array($frame, $merged[$id], true)) {
                                $merged[$id][] = $frame;
                            }
                        }
                    }
                }
            }

            return ['frames' => $merged, 'major' => $major ?? 3, 'audioOffset' => $offset];
        } finally {
            fclose($handle);
        }
    }

    /**
     * A text value in the encoding of the given ID3v2 version.
     *
     * @return array{encodingid: int, data: string}
     */
    public function text(string $value, int $major): array
    {
        if ($major === 4) {
            return ['encodingid' => 3, 'data' => $value];
        }

        // 2.3 has no UTF-8: ISO-8859-1 when plain ASCII, else UTF-16 + BOM.
        return preg_match('/[^\x00-\x7F]/', $value) === 1
            ? ['encodingid' => 1, 'data' => "\xFF\xFE" . self::utf16le($value)]
            : ['encodingid' => 0, 'data' => $value];
    }

    /**
     * Writes the frames (+ ID3v1 when the file had one) with a backup and a
     * lost-frame check.
     *
     * @param array<string, mixed> $info analysis of the file before writing
     * @param array<string, list<array<string, mixed>>> $frames
     * @param list<string> $changedFrames frame ids allowed to change (added, removed, recounted)
     * @param array<string, string> $id3v1 title, artist, album, year, comment, track_number, genre (UTF-8)
     * @param ?array{frames: array<string, list<array<string, mixed>>>, major: int, audioOffset: int} $stacked from {@see stackedTags()}: the stacked tags are replaced by one
     *
     * @throws \RuntimeException when writing fails or would lose frames (the file is left as it was)
     */
    public function write(string $file, array $info, array $frames, array $changedFrames, array $id3v1, ?array $stacked = null): void
    {
        $this->loadWriter();
        $before = $stacked !== null
            ? array_map(static fn(array $list): int => \count($list), $stacked['frames'])
            : $this->frameCounts($info);

        $backup = tempnam(sys_get_temp_dir(), 'tagbak');
        if ($backup === false || !copy($file, $backup)) {
            throw new \RuntimeException('could not back up the file before writing');
        }

        try {
            $major = $stacked['major'] ?? $this->majorVersion($info);
            if ($stacked !== null) {
                // Drop every stacked tag: the merged one is written in their place.
                $this->stripLeading($file, $stacked['audioOffset']);
            }
            // Frames we set ourselves must be written, never dropped.
            $ours = $changedFrames;
            $changedFrames = array_merge($changedFrames, $this->repair($frames, $major));
            // Frames this ID3v2 version does not define (or non-standard ones)
            // are refused by the writer: drop them and try again.
            for ($round = 1; ; ++$round) {
                $writer = new \getid3_write_id3v2();
                $writer->filename = $file;
                $writer->majorversion = $major;
                $writer->tag_data = $frames;
                if ($writer->WriteID3v2()) {
                    break;
                }
                $refused = array_values(array_filter(
                    $this->refusedFrames($writer->errors),
                    // A text frame refused for an unforeseen reason is never
                    // dropped silently: better fail and keep the file intact.
                    fn(string $id): bool => $id[0] !== 'T' || $this->notAllowed($id, $writer->errors),
                ));
                if ($refused === [] || $round >= 5 || array_intersect($refused, $ours) !== []) {
                    throw new \RuntimeException('getID3: ' . implode('; ', $writer->errors));
                }
                foreach ($refused as $id) {
                    unset($frames[$id]);
                    $changedFrames[] = $id;
                }
            }

            if (isset($info['id3v1'])) {
                $v1 = new \getid3_writetags();
                $v1->filename = $file;
                $v1->tagformats = ['id3v1'];
                $v1->overwrite_tags = true;
                $v1->tag_encoding = 'UTF-8';
                $v1->tag_data = array_map(static fn(string $v): array => [$v], array_filter($id3v1, static fn(string $v): bool => $v !== ''));
                if (!$v1->WriteTags()) {
                    throw new \RuntimeException('getID3 (ID3v1): ' . implode('; ', $v1->errors));
                }
            }

            clearstatcache(true, $file);
            $afterInfo = $this->analyze($file);
            if ($stacked !== null && ($afterInfo['audio']['dataformat'] ?? null) !== 'mp3') {
                throw new \RuntimeException('the MPEG audio is still not found after merging the stacked tags, file restored');
            }
            $after = $this->frameCounts($afterInfo);
            $lost = [];
            foreach ($before as $frame => $count) {
                if (!\in_array($frame, $changedFrames, true) && !\in_array($frame, self::TECHNICAL_FRAMES, true)
                    && ($after[$frame] ?? 0) !== $count) {
                    $lost[] = $frame;
                }
            }
            if ($lost !== []) {
                throw new \RuntimeException('frames would be lost (' . implode(', ', $lost) . '), file restored');
            }
        } catch (\Throwable $e) {
            copy($backup, $file);
            clearstatcache(true, $file);

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        } finally {
            @unlink($backup);
        }
    }

    /**
     * Fixes malformed frames of downloaded files that getid3_write_id3v2
     * refuses to write back: a picture typed "JPG" instead of "image/jpeg", an
     * object without MIME type. URL frames getID3 would silently drop (empty
     * URL, or an e-mail address in place of one) are dropped here, openly.
     *
     * @param array<string, list<array<string, mixed>>> $frames
     *
     * @return list<string> frame ids whose count changes
     */
    private function repair(array &$frames, int $major): array
    {
        $changed = [];

        // The recording date has a different frame in each version: carry it
        // over (2.4 TDRC "2008-03-24" ↔ 2.3 TYER "2008").
        [$from, $to] = $major === 3 ? ['TDRC', 'TYER'] : ['TYER', 'TDRC'];
        if (isset($frames[$from])) {
            $year = substr((string) preg_replace('/[^0-9]/', '', (string) ($frames[$from][0]['data'] ?? '')), 0, 4);
            if (!isset($frames[$to]) && \strlen($year) === 4) {
                $frames[$to] = [['encodingid' => 0, 'data' => $year]];
                $changed[] = $to;
            }
            unset($frames[$from]);
            $changed[] = $from;
        }

        $keep = static function (string $id, callable $accept) use (&$frames, &$changed): void {
            $list = $frames[$id] ?? [];
            $kept = array_values(array_filter($list, $accept));
            if (\count($kept) !== \count($list)) {
                $changed[] = $id;
                $frames[$id] = $kept;
                if ($kept === []) {
                    unset($frames[$id]);
                }
            }
        };

        // getID3 parses some frames into structured fields and drops their raw
        // bytes (volume/EQ adjustments RVA2/RVAD/EQU2/EQUA/RVRB, synced lyrics
        // SYLT/SYTC, ETCO/MLLT, malformed lyrics): they cannot be written back.
        // Fields getID3 returned as lists (seen as "Array to string conversion")
        // cannot be written back either.
        foreach (array_keys($frames) as $id) {
            $keep($id, static fn(array $f): bool => ($id === 'POPM' || \array_key_exists('data', $f))
                && array_filter($f, static fn(mixed $v): bool => !\is_scalar($v) && $v !== null) === []);
        }
        // ID3v2.3 knows no UTF-8 (encoding 3) nor BOM-less UTF-16BE (2): texts
        // from 2.4 tags (stacked tags, retagged files) are re-encoded.
        if ($major === 3) {
            foreach ($frames as $id => $list) {
                foreach ($list as $i => $frame) {
                    $encoding = (int) ($frame['encodingid'] ?? 0);
                    if ($encoding !== 2 && $encoding !== 3) {
                        continue;
                    }
                    $decoded = [];
                    foreach (['data', 'description'] as $key) {
                        // URL frames keep a plain ISO-8859-1 URL in "data".
                        if (isset($frame[$key]) && !($key === 'data' && $id[0] === 'W')) {
                            $decoded[$key] = $this->decode((string) $frame[$key], $encoding);
                        }
                    }
                    $utf16 = preg_match('/[^\x00-\x7F]/', implode('', $decoded)) === 1;
                    $frames[$id][$i]['encodingid'] = $utf16 ? 1 : 0;
                    foreach ($decoded as $key => $value) {
                        $frames[$id][$i][$key] = $utf16 ? "\xFF\xFE" . self::utf16le($value) : $value;
                    }
                }
            }
        }

        // Garbage language codes (three backslashes seen in downloads) become "XXX", the ID3 code for unknown.
        foreach (['COMM', 'USLT'] as $id) {
            foreach ($frames[$id] ?? [] as $i => $frame) {
                if (\getid3_id3v2::LanguageLookup((string) ($frame['language'] ?? ''), true) === '') {
                    $frames[$id][$i]['language'] = 'XXX';
                }
            }
        }
        // Only one TXXX per description ("Rating" twice seen in downloads).
        $seenTxxx = [];
        $keep('TXXX', function (array $f) use (&$seenTxxx): bool {
            $key = mb_strtolower($this->decode((string) ($f['description'] ?? ''), (int) ($f['encodingid'] ?? 0)));
            if (isset($seenTxxx[$key])) {
                return false;
            }

            return $seenTxxx[$key] = true;
        });
        // Only one COMM / USLT per language + description (ID3v2 rule some
        // taggers break): the first one is kept.
        foreach (['COMM', 'USLT'] as $id) {
            $seen = [];
            $keep($id, static function (array $f) use (&$seen): bool {
                $key = strtolower((string) ($f['language'] ?? '')) . "\0" . ($f['description'] ?? '');
                if (isset($seen[$key])) {
                    return false;
                }

                return $seen[$key] = true;
            });
        }

        foreach ($frames['APIC'] ?? [] as $i => $picture) {
            $mime = (string) ($picture['mime'] ?? '');
            if (!str_starts_with(strtolower($mime), 'image/') || !\getid3_write_id3v2::IsValidMIMEstring($mime)) {
                $frames['APIC'][$i]['mime'] = $this->imageMime($mime, (string) ($picture['data'] ?? ''));
            }
        }
        foreach ($frames['GEOB'] ?? [] as $i => $object) {
            if (!\getid3_write_id3v2::IsValidMIMEstring((string) ($object['mime'] ?? ''))) {
                $frames['GEOB'][$i]['mime'] = 'application/octet-stream';
            }
        }
        foreach (array_keys($frames) as $id) {
            if ($id[0] === 'W') {
                $keep($id, static fn(array $f): bool => \getid3_write_id3v2::IsValidURL((string) ($f['data'] ?? ''), false));
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Frame ids the writer refused ("Frame not allowed in ID3v2.3: RVA2",
     * 'Frame "XXXX" is NOT allowed', 'GenerateID3v2FrameData() failed for "XXXX"').
     *
     * @param array<mixed> $errors
     *
     * @return list<string>
     */
    private function refusedFrames(array $errors): array
    {
        $ids = [];
        foreach ($errors as $error) {
            // Last resort for malformed frames nothing else repaired:
            // 'GenerateID3v2FrameData() failed for "COMM"'.
            if (preg_match('/Frame not allowed in ID3v2\.\d: ([A-Z0-9]{4})|Frame "([A-Z0-9]{4})" is NOT allowed|failed for "([A-Z0-9]{4})"/', strip_tags((string) $error), $m) === 1) {
                $ids[] = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A raw ID3v2 text (ISO-8859-1, UTF-16 + BOM, UTF-16BE, UTF-8) as UTF-8.
     * Taggers do mislabel: "UTF-8" that is not valid UTF-8 is read as ISO-8859-1.
     */
    private function decode(string $raw, int $encodingId): string
    {
        return match ($encodingId) {
            1 => (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16'),
            2 => (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE'),
            3 => self::validUtf8($raw),
            default => (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'),
        };
    }

    private static function validUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /** UTF-16LE bytes (no BOM) of a UTF-8 — or mislabelled ISO-8859-1 — string. */
    private static function utf16le(string $value): string
    {
        return (string) mb_convert_encoding(self::validUtf8($value), 'UTF-16LE', 'UTF-8');
    }

    /**
     * The writer said this frame does not exist in the tag's version.
     *
     * @param array<mixed> $errors
     */
    private function notAllowed(string $id, array $errors): bool
    {
        foreach ($errors as $error) {
            $error = strip_tags((string) $error);
            if (str_contains($error, 'Frame not allowed in ID3v2') && str_ends_with(trim($error), $id)
                || str_contains($error, 'Frame "' . $id . '" is NOT allowed')) {
                return true;
            }
        }

        return false;
    }

    private function imageMime(string $declared, string $bytes): string
    {
        return match (true) {
            \in_array(strtolower($declared), ['jpg', 'jpeg', 'image/jpg'], true) => 'image/jpeg',
            strtolower($declared) === 'png' => 'image/png',
            strtolower($declared) === 'gif' => 'image/gif',
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP') => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Removes the first $bytes of the file (in place, streamed). */
    private function stripLeading(string $file, int $bytes): void
    {
        $tmp = $file . '.strip-' . bin2hex(random_bytes(4));
        $in = fopen($file, 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('cannot rewrite the file');
        }
        fseek($in, $bytes);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot rewrite the file');
        }
        clearstatcache(true, $file);
    }

    /**
     * Parsed frame → the fields getid3_write_id3v2 expects (raw bytes kept).
     *
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function writable(string $id, array $frame): array
    {
        $out = array_intersect_key($frame, array_flip([
            'encodingid', 'data', 'description', 'language', 'ownerid', 'mime', 'picturetypeid',
            'filename', 'email', 'rating', 'groupsymbol', 'timestampformat', 'contenttypeid',
        ]));
        if ($id === 'APIC' && !isset($out['mime'])) {
            $out['mime'] = $frame['image_mime'] ?? 'image/jpeg';
        }
        // URL frames come back as "url", objects as "objectdata", POPM's play count as "counter".
        if ($id[0] === 'W' && isset($frame['url'])) {
            $out['data'] = $frame['url'];
        }
        if ($id === 'GEOB' && isset($frame['objectdata'])) {
            $out['data'] = $frame['objectdata'];
        }
        if ($id === 'POPM') {
            $out['data'] = $frame['counter'] ?? 0;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, int> ID3v2 frame id => number of frames
     */
    private function frameCounts(array $info): array
    {
        $counts = [];
        foreach (\is_array($info['id3v2'] ?? null) ? $info['id3v2'] : [] as $frame => $frames) {
            if (\is_string($frame) && preg_match('/^[A-Z][A-Z0-9]{3}$/', $frame) === 1 && \is_array($frames)) {
                $counts[$frame] = \count($frames);
            }
        }

        return $counts;
    }

    /**
     * write.php needs getid3.php to have run first (GETID3_INCLUDEPATH), and
     * the ID3v1/ID3v2 writers are only pulled in on demand.
     */
    private function loadWriter(): void
    {
        class_exists(\getID3::class);
        foreach (['getid3_writetags' => 'write.php', 'getid3_write_id3v2' => 'write.id3v2.php', 'getid3_write_id3v1' => 'write.id3v1.php'] as $class => $script) {
            if (!class_exists($class)) {
                if (!is_file($this->getid3Dir . '/' . $script)) {
                    throw new \RuntimeException('getID3 write module unavailable');
                }
                require_once $this->getid3Dir . '/' . $script;
            }
        }
    }
}
