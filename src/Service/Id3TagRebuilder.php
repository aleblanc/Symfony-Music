<?php

namespace App\Service;

/**
 * Rewrites an MP3's ID3v2 tag without losing frames.
 *
 * The bundled getID3 cannot merge into an existing tag (overwrite_tags=false
 * throws): the whole tag is rebuilt from what getID3 reads — every text
 * frame, pictures, TXXX, UFID, WOAF (Spotify URL), POPM rating — then the
 * requested changes are applied. The file is backed up first and every frame
 * type not deliberately changed must come back with the same count;
 * otherwise the backup is restored.
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

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed> getid3_writetags::$tag_data reproducing the current ID3v2 tag
     */
    public function rebuild(array $info): array
    {
        $this->loadWriter();
        /** @var array<string, list<mixed>> $comments */
        $comments = $info['comments'] ?? [];
        $id3v2 = \is_array($info['id3v2'] ?? null) ? $info['id3v2'] : [];
        $data = [];

        foreach ($comments as $key => $values) {
            if (\in_array($key, ['picture', 'text', 'totaltracks', 'totaldiscs', 'popularimeter', 'unique_file_identifier'], true)
                || \getid3_write_id3v2::ID3v2ShortFrameNameLookup(3, $key) === '') {
                continue;
            }
            $data[$key] = array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $values);
        }
        // getID3 splits TRCK "7/12" / TPOS "1/2" on read.
        foreach (['track_number' => 'totaltracks', 'part_of_a_set' => 'totaldiscs'] as $key => $total) {
            if (isset($data[$key][0], $comments[$total][0]) && !str_contains($data[$key][0], '/')) {
                $data[$key][0] .= '/' . $comments[$total][0];
            }
        }
        foreach (\is_array($id3v2['APIC'] ?? null) ? $id3v2['APIC'] : [] as $apic) {
            $data['attached_picture'][] = [
                'data' => $apic['data'] ?? '',
                'picturetypeid' => $apic['picturetypeid'] ?? 3,
                'description' => $apic['description'] ?? '',
                'mime' => $apic['mime'] ?? $apic['image_mime'] ?? 'image/jpeg',
            ];
        }
        foreach (\is_array($id3v2['TXXX'] ?? null) ? $id3v2['TXXX'] : [] as $txxx) {
            $data['text'][] = ['description' => $txxx['description'] ?? '', 'data' => $txxx['data'] ?? ''];
        }
        // getID3 writes a single POPM and a single UFID; with several, the
        // frame-count check after writing catches the loss and restores.
        if (isset($id3v2['POPM'][0]) && \is_array($id3v2['POPM'][0])) {
            $popm = $id3v2['POPM'][0];
            $data['popularimeter'] = ['email' => $popm['email'] ?? '', 'rating' => $popm['rating'] ?? 0, 'data' => $popm['counter'] ?? 0];
        }
        if (isset($id3v2['UFID'][0]) && \is_array($id3v2['UFID'][0])) {
            $data['unique_file_identifier'] = ['ownerid' => $id3v2['UFID'][0]['ownerid'] ?? '', 'data' => $id3v2['UFID'][0]['data'] ?? ''];
        }

        return $data;
    }

    /**
     * Writes $tagData (ID3v1 + ID3v2.3) with a backup and a frame check.
     *
     * @param array<string, mixed> $info analysis of the file before writing
     * @param array<string, mixed> $tagData
     * @param list<string> $changedFrames ID3v2 frame ids allowed to change (added, removed, recounted)
     *
     * @throws \RuntimeException when writing fails or would lose frames (the file is left as it was)
     */
    public function write(string $file, array $info, array $tagData, array $changedFrames): void
    {
        $this->loadWriter();
        $before = $this->frameCounts($info);

        $writer = new \getid3_writetags();
        $writer->filename = $file;
        $writer->tagformats = ['id3v1', 'id3v2.3'];
        $writer->overwrite_tags = true;
        $writer->remove_other_tags = false;
        $writer->tag_encoding = 'UTF-8';
        $writer->tag_data = $tagData;

        $backup = tempnam(sys_get_temp_dir(), 'tagbak');
        if ($backup === false || !copy($file, $backup)) {
            throw new \RuntimeException('could not back up the file before writing');
        }

        try {
            if (!$writer->WriteTags()) {
                throw new \RuntimeException('getID3: ' . implode('; ', $writer->errors));
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

    /** ID3v2.3 frame id of a getID3 tag_data key ("track_number" → "TRCK"), '' when unknown. */
    public function frameId(string $key): string
    {
        $this->loadWriter();

        return (string) \getid3_write_id3v2::ID3v2ShortFrameNameLookup(3, $key);
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
            if (\is_string($frame) && preg_match('/^[A-Z0-9]{4}$/', $frame) === 1 && \is_array($frames)) {
                $counts[$frame] = \count($frames);
            }
        }

        return $counts;
    }

    /**
     * write.php needs getid3.php to have run first (GETID3_INCLUDEPATH), and
     * only pulls write.id3v2.php in when writing, while rebuild() needs its
     * frame-name lookup beforehand.
     */
    private function loadWriter(): void
    {
        class_exists(\getID3::class);
        foreach (['getid3_writetags' => 'write.php', 'getid3_write_id3v2' => 'write.id3v2.php'] as $class => $script) {
            if (!class_exists($class)) {
                if (!is_file($this->getid3Dir . '/' . $script)) {
                    throw new \RuntimeException('getID3 write module unavailable');
                }
                require_once $this->getid3Dir . '/' . $script;
            }
        }
    }
}
