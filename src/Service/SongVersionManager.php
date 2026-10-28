<?php

namespace App\Service;

use App\Entity\Music;
use App\Exception\UserFacingException;

/**
 * Lists and restores on-disk backups of an audio file's previous versions.
 *
 * Ported from the legacy `get_song_versions` / `restore_song_version`
 * actions. The legacy app never stored versions in the database — it kept
 * timestamped copies of the raw audio file in a sibling
 * `.file_version/<basename>/` directory next to the music library root, and
 * `restore_song_version` copied one of those backups back over the live
 * file (after first snapshotting the current state). This service
 * reproduces that behaviour instead of a DB-backed one, since there is no
 * `music_versions` table to map.
 */
class SongVersionManager
{
    public function __construct(private readonly string $musicDir) {}

    /**
     * @return list<array{name: string, mtime: int, sizeMb: float}> newest first, capped at 10 (mirrors the legacy cap)
     */
    public function listVersions(Music $song): array
    {
        $dir = $this->versionDir($song);
        if (!is_dir($dir)) {
            return [];
        }

        $versions = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $versions[] = [
                'name' => $name,
                'mtime' => (int) filemtime($path),
                'sizeMb' => round(filesize($path) / 1048576, 2),
            ];
        }

        usort($versions, static fn(array $a, array $b): int => $b['mtime'] - $a['mtime']);

        return \array_slice($versions, 0, 10);
    }

    /**
     * Restores a named backup over the live file, after snapshotting the
     * current state so the restore itself is reversible.
     *
     * @throws \RuntimeException if the requested version or the live file is missing
     */
    public function restore(Music $song, string $versionName): void
    {
        $versionName = basename($versionName);
        $dir = $this->versionDir($song);
        $source = $dir . '/' . $versionName;

        if (!is_file($source)) {
            throw new UserFacingException('Version not found.', 'error.song.version_not_found');
        }

        $file = (string) $song->getFile();
        if (!is_file($file)) {
            throw new UserFacingException('Live audio file missing on disk.', 'error.song.audio_missing');
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        // Snapshot the current file before overwriting it, so the restore is reversible.
        copy($file, $dir . '/' . basename($file) . '_' . date('Y-m-d_H-i-s'));

        copy($source, $file);
        $song->setLastModified(time());
    }

    private function versionDir(Music $song): string
    {
        return rtrim($this->musicDir, '/') . '/.file_version/' . basename((string) $song->getFile());
    }
}
