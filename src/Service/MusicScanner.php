<?php

namespace App\Service;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Indexes an on-disk music library into the database.
 *
 * Faithful port of the legacy `perform_full_scan()` from the monolithic
 * index.php: recursive discovery, getID3 tag extraction with filename/sibling
 * fallbacks, automatic per-artist account creation, and pruning of rows whose
 * files disappeared. Rewritten as a testable, framework-native service instead
 * of an inline streaming handler.
 */
class MusicScanner
{
    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'flac', 'ogg', 'wav'];
    private const EXCLUDED_DIRS = ['.git', 'getid3', '.drive_trash_bin', '.drive_thumbnails', '.file_version', '.tmp_db', 'covers'];
    private const COVER_CANDIDATES = ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'artwork.jpg'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MusicRepository $musicRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $musicDir,
    ) {}

    public function getMusicDir(): string
    {
        return $this->musicDir;
    }

    /**
     * Runs a full scan.
     *
     * @param callable(string):void|null $progress optional callback invoked with a human-readable status line
     * @param bool $force when true, re-reads tags for every file even if its
     *                    mtime is unchanged (mirrors the legacy `force_rescan`
     *                    action, which also refreshes stale cover art)
     *
     * @return array{added:int, updated:int, removed:int, skipped:int}
     */
    public function scan(?callable $progress = null, bool $force = false): array
    {
        $log = $progress ?? static function (): void {};

        if (!is_dir($this->musicDir)) {
            throw new \RuntimeException(\sprintf('Music directory "%s" does not exist.', $this->musicDir));
        }

        if (!class_exists(\getID3::class)) {
            throw new \RuntimeException('getID3 library is not available (check lib/getid3 autoload).');
        }

        // Keep only the library user's id: the periodic em->clear() below
        // detaches every managed entity (Doctrine ORM 3's clear() ignores its
        // argument and always clears everything), so holding the object itself
        // across the loop would leave a detached User attached to new Music
        // rows and blow up on flush. A getReference() by id is always
        // re-attachable.
        $libraryId = (int) $this->resolveLibraryUser()->getId();

        $log('Discovering audio files...');
        $filesOnDisk = $this->discoverFiles();
        $log(\sprintf('Found %d audio files on disk.', \count($filesOnDisk)));

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);

        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $artistCache = $this->buildArtistCache();
        $processed = 0;

        foreach ($filesOnDisk as $filePath => $mtime) {
            $existing = $this->musicRepository->findOneByFile($filePath);

            // Unchanged files are skipped so re-scans stay cheap, unless a
            // forced rescan was requested.
            if (!$force && $existing !== null && $existing->getLastModified() === $mtime) {
                ++$stats['skipped'];
                continue;
            }

            try {
                $meta = $this->analyze($getID3, $filePath);
            } catch (\Throwable $e) {
                $log(\sprintf('  ! Error on %s: %s', basename($filePath), $e->getMessage()));
                continue;
            }

            if ($existing !== null) {
                $this->applyMetadata($existing, $meta, $mtime);
                ++$stats['updated'];
                $log(\sprintf('  ~ Updated: %s', basename($filePath)));
            } else {
                $music = new Music();
                $music->setFile($filePath);
                $owner = $this->resolveOwner($meta['artist'], $libraryId, $artistCache, $log);
                $music->setOwner($owner);
                $this->applyMetadata($music, $meta, $mtime);
                $this->em->persist($music);
                ++$stats['added'];
                $log(\sprintf('  + Added: %s', basename($filePath)));
            }

            if ((++$processed % 25) === 0) {
                $this->em->flush();
                // Full clear (ORM 3 removed the per-class argument): frees the
                // just-flushed Music rows. Owners are re-fetched by id via
                // getReference()/the artist cache on the next iterations.
                $this->em->clear();
            }
        }

        $this->em->flush();

        // Prune tracks whose files vanished from disk.
        $log('Pruning missing files...');
        foreach ($this->musicRepository->findAll() as $music) {
            $musicFile = (string) $music->getFile();
            if (!isset($filesOnDisk[$musicFile]) && !file_exists($musicFile)) {
                $this->em->remove($music);
                ++$stats['removed'];
            }
        }
        $this->em->flush();

        return $stats;
    }

    /**
     * @return array<string, int> map of absolute path => mtime
     */
    private function discoverFiles(): array
    {
        $files = [];

        $dir = new \RecursiveDirectoryIterator(
            $this->musicDir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
        );
        $filter = new \RecursiveCallbackFilterIterator($dir, static function (\SplFileInfo $current): bool {
            if ($current->isDir()) {
                return !\in_array($current->getFilename(), self::EXCLUDED_DIRS, true);
            }

            return true;
        });

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($file->isDir()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            if (\in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $files[$path] = $file->getMTime();
            }
        }

        return $files;
    }

    /**
     * @return array{title:string, artist:string, album:string, genre:string, year:int, duration:int, bitrate:int, image:?string, imageType:?string, lyrics:?string}
     */
    private function analyze(\getID3 $getID3, string $filePath): array
    {
        $info = $getID3->analyze($filePath);
        \getid3_lib::CopyTagsToComments($info);
        $comments = $info['comments'] ?? [];

        return [
            'title' => $this->tag($comments, 'title', pathinfo($filePath, \PATHINFO_FILENAME)),
            'artist' => $this->tag($comments, 'artist', 'Unknown Artist'),
            'album' => $this->tag($comments, 'album', 'Unknown Album'),
            'genre' => $this->tag($comments, 'genre', 'Unknown Genre'),
            'year' => (int) ($comments['year'][0] ?? 0),
            'duration' => (int) ($info['playtime_seconds'] ?? 0),
            'bitrate' => (int) ($info['audio']['bitrate'] ?? 0),
            'image' => $this->extractCover($comments, $filePath),
            // Cover bytes carry no mime column in the legacy schema; the type is
            // sniffed from the bytes when served, so none is recorded at scan time.
            'imageType' => null,
            'lyrics' => $this->tag($comments, 'unsynchronised_lyric', '') ?: null,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function applyMetadata(Music $music, array $meta, int $mtime): void
    {
        $music->setTitle($meta['title']);
        $music->setArtist($meta['artist']);
        $music->setAlbum($meta['album']);
        $music->setGenre($meta['genre']);
        $music->setYear($meta['year'] ?: null);
        $music->setDuration($meta['duration']);
        $music->setBitrate($meta['bitrate']);
        $music->setLastModified($mtime);
        if ($meta['lyrics'] !== null) {
            $music->setLyrics($meta['lyrics']);
        }
        // Only overwrite artwork when we actually found some.
        if ($meta['image'] !== null) {
            $music->setImage($meta['image']);
        }
    }

    /**
     * @param array<string, mixed> $comments
     *
     * @return ?string cover art bytes, or null when none found
     */
    private function extractCover(array $comments, string $filePath): ?string
    {
        $raw = $comments['picture'][0]['data'] ?? null;

        if ($raw === null) {
            $dir = pathinfo($filePath, \PATHINFO_DIRNAME);
            $base = pathinfo($filePath, \PATHINFO_FILENAME);
            $candidates = array_merge([$base . '.jpg', $base . '.png'], self::COVER_CANDIDATES);
            foreach ($candidates as $candidate) {
                $coverPath = $dir . '/' . $candidate;
                if (is_file($coverPath)) {
                    $raw = @file_get_contents($coverPath) ?: null;
                    if ($raw !== null) {
                        break;
                    }
                }
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $comments
     */
    private function tag(array $comments, string $key, string $default): string
    {
        $value = $comments[$key][0] ?? null;
        if (!\is_string($value)) {
            return $default;
        }
        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    /**
     * Resolves the owning user for a track, creating a placeholder artist
     * account on first sight (mirrors the legacy auto-account behaviour).
     *
     * @param array<string, int> $cache artist(lowercased) => user id
     * @param callable(string):void $log
     */
    private function resolveOwner(string $artistTag, int $libraryId, array &$cache, callable $log): User
    {
        $parts = preg_split(
            '/\s*(?:;|\||\s+&\s+|\s+feat\.?\s+|\s+ft\.?\s+|\s+featuring\s+)\s*|\s*,\s*(?!(?:the|a|an|jr|sr)\b)/i',
            $artistTag,
        );
        $mainArtist = trim($parts === false ? $artistTag : (string) ($parts[0] ?? ''));

        if ($mainArtist === '' || $mainArtist === 'Unknown Artist') {
            return $this->userReference($libraryId);
        }

        $key = mb_strtolower($mainArtist);
        if (isset($cache[$key])) {
            return $this->userReference($cache[$key]);
        }

        $log(\sprintf('  * New artist "%s": creating account.', $mainArtist));

        $base = $this->sanitizeForPath($mainArtist);
        $email = ($base === 'unknown' ? 'unknown1' : $base) . '@mail.com';
        $counter = 1;
        while ($this->userRepository->findOneBy(['email' => $email]) !== null
            || $this->userRepository->findOneBy(['artist' => $mainArtist]) !== null) {
            // Artist name collision: fall back to the shared library account.
            $existing = $this->userRepository->findOneBy(['artist' => $mainArtist]);
            if ($existing !== null) {
                $cache[$key] = $existing->getId() ?? throw new \LogicException('Persisted user must have an id.');

                return $existing;
            }
            ++$counter;
            $email = $base . $counter . '@mail.com';
        }

        $user = new User();
        $user->setEmail($email);
        $user->setArtist($mainArtist);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
        $this->userRepository->save($user);
        $cache[$key] = $user->getId() ?? throw new \LogicException('Persisted user must have an id.');

        return $user;
    }

    /**
     * Resolves a managed {@see User} reference by id, guarding the invariant
     * that the id (a library account or a cached artist) always exists.
     */
    private function userReference(int $id): User
    {
        return $this->em->getReference(User::class, $id)
            ?? throw new \LogicException('User reference could not be resolved.');
    }

    private function resolveLibraryUser(): User
    {
        $library = $this->userRepository->findOneBy(['status' => 'super_admin'])
            ?? $this->userRepository->findOneBy([], ['id' => 'ASC']);

        if ($library !== null) {
            return $library;
        }

        // Bootstrap a shared "Music Library" account the first time we scan an
        // empty database (the legacy super-admin bypass account).
        $library = new User();
        $library->setEmail('musiclibrary@mail.com');
        $library->setArtist('Music Library');
        $library->setStatus('super_admin');
        $library->setPasswordHash($this->passwordHasher->hashPassword($library, bin2hex(random_bytes(16))));
        $this->userRepository->save($library);

        return $library;
    }

    /**
     * @return array<string, int>
     */
    private function buildArtistCache(): array
    {
        $cache = [];
        foreach ($this->userRepository->findAll() as $user) {
            if ($user->getArtist() !== null) {
                $cache[mb_strtolower($user->getArtist())] = $user->getId() ?? throw new \LogicException('Persisted user must have an id.');
            }
        }

        return $cache;
    }

    private function sanitizeForPath(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value !== '' ? $value : 'unknown';
    }
}
