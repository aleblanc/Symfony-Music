<?php

namespace App\Service\Spotify;

use App\Entity\Music;

/**
 * Stores a song's open.spotify.com URL in its MP3 file, in the ID3v2 WOAF
 * frame ("official audio file webpage"), without losing the rest of the tag.
 *
 * The bundled getID3 can't merge into an existing tag (overwrite_tags=false
 * throws), so the whole tag is rebuilt from what getID3 reads: text frames,
 * pictures, TXXX, POPM rating. The file is backed up first; after writing,
 * every frame type must still be present with the same count, otherwise the
 * backup is restored and an exception explains what would have been lost.
 */
class SpotifyTagWriter
{
    public function __construct(private readonly string $getid3Dir) {}

    /**
     * @return bool false when the file already carries this exact URL
     *
     * @throws \RuntimeException when the file can't be tagged safely (it is left untouched)
     */
    public function write(Music $song, string $url): bool
    {
        $file = (string) $song->getFile();
        if (!is_file($file) || !is_writable($file)) {
            throw new \RuntimeException('file missing or not writable');
        }
        if (strtolower(pathinfo($file, \PATHINFO_EXTENSION)) !== 'mp3') {
            throw new \RuntimeException('only MP3 can be tagged (FLAC/OGG need external tools, getID3 cannot write M4A)');
        }
        $this->loadWriter();

        $before = $this->analyze($file);
        if (\in_array($url, $before['comments']['url_file'] ?? [], true)) {
            return false;
        }
        $framesBefore = $this->frameCounts($before);
        $framesBefore['WOAF'] = 1;

        $writer = new \getid3_writetags();
        $writer->filename = $file;
        $writer->tagformats = isset($before['id3v1']) ? ['id3v1', 'id3v2.3'] : ['id3v2.3'];
        $writer->overwrite_tags = true;
        $writer->remove_other_tags = false;
        $writer->tag_encoding = 'UTF-8';
        $writer->tag_data = $this->rebuildTagData($before);
        $writer->tag_data['url_file'] = [$url];

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
            foreach ($framesBefore as $frame => $count) {
                if (($after[$frame] ?? 0) !== $count) {
                    $lost[] = $frame;
                }
            }
            if ($lost !== []) {
                throw new \RuntimeException('frames would change (' . implode(', ', $lost) . '), file restored');
            }
        } catch (\Throwable $e) {
            copy($backup, $file);
            clearstatcache(true, $file);

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        } finally {
            @unlink($backup);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed> getid3_writetags::$tag_data reproducing the current ID3v2 tag
     */
    private function rebuildTagData(array $info): array
    {
        /** @var array<string, list<mixed>> $comments */
        $comments = $info['comments'] ?? [];
        /** @var array<string, mixed> $id3v2 */
        $id3v2 = $info['id3v2'] ?? [];
        $data = [];

        foreach ($comments as $key => $values) {
            if (\in_array($key, ['picture', 'text', 'url_file', 'totaltracks', 'totaldiscs', 'popularimeter'], true)
                || \getid3_write_id3v2::ID3v2ShortFrameNameLookup(3, $key) === '') {
                continue;
            }
            $data[$key] = array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $values);
        }
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
        // The writer accepts a single POPM; with several, the frame-count check
        // after writing catches the loss and restores the file.
        if (isset($id3v2['POPM'][0]) && \is_array($id3v2['POPM'][0])) {
            $popm = $id3v2['POPM'][0];
            $data['popularimeter'] = ['email' => $popm['email'] ?? '', 'rating' => $popm['rating'] ?? 0, 'data' => $popm['counter'] ?? 0];
        }

        return $data;
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
     * @return array<string, mixed>
     */
    private function analyze(string $file): array
    {
        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file);
        \getid3_lib::CopyTagsToComments($info);

        return $info;
    }

    /**
     * write.php needs getid3.php to have run first (GETID3_INCLUDEPATH), and
     * only pulls write.id3v2.php in when writing, while rebuildTagData()
     * needs its frame-name lookup beforehand.
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
