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

    /** ID3v2 version to write: the file's own (3 or 4); 2.2 and untagged files get 2.3. */
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
            ? ['encodingid' => 1, 'data' => "\xFF\xFE" . (string) iconv('UTF-8', 'UTF-16LE', $value)]
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
     *
     * @throws \RuntimeException when writing fails or would lose frames (the file is left as it was)
     */
    public function write(string $file, array $info, array $frames, array $changedFrames, array $id3v1): void
    {
        $this->loadWriter();
        $before = $this->frameCounts($info);

        $backup = tempnam(sys_get_temp_dir(), 'tagbak');
        if ($backup === false || !copy($file, $backup)) {
            throw new \RuntimeException('could not back up the file before writing');
        }

        try {
            $major = $this->majorVersion($info);
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
                $refused = $this->refusedFrames($writer->errors);
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
            $after = $this->frameCounts($this->analyze($file));
            $lost = [];
            foreach ($before as $frame => $count) {
                if (!\in_array($frame, $changedFrames, true) && ($after[$frame] ?? 0) !== $count) {
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
        foreach (array_keys($frames) as $id) {
            if ($id !== 'POPM') {
                $keep($id, static fn(array $f): bool => \array_key_exists('data', $f));
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
     * @param list<string> $errors
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
