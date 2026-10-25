<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use App\Service\CoverImageProcessor;
use App\Service\MediaResponder;
use App\Subsonic\IdCodec;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media: stream / download (original file, Range/206; transcoding parameters
 * are ignored for now), cover art (one image per album, optionally resized
 * and cached on disk) and lyrics (LRC → synced lines).
 */
final class MediaHandler implements SubsonicHandler
{
    private const MAX_COVER_SIZE = 1500;

    public function __construct(
        private readonly MusicRepository $music,
        private readonly LibraryQuery $library,
        private readonly ArtistProfileRepository $profiles,
        private readonly MediaResponder $media,
        private readonly CoverImageProcessor $covers,
        private readonly Connection $db,
        #[Autowire('%kernel.cache_dir%/subsonic-covers')] private readonly string $coverCacheDir,
    ) {}

    public static function methods(): array
    {
        return ['stream', 'download', 'getCoverArt', 'getLyricsBySongId', 'getLyrics'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array|Response
    {
        return match ($method) {
            'stream', 'download' => $this->media->audio($this->audioFile($request, $user), $request->httpRequest(), $method === 'download'),
            'getCoverArt' => $this->coverArt($request, $user),
            'getLyricsBySongId' => ['lyricsList' => ['structuredLyrics' => $this->structuredLyrics($this->song($request, $user))]],
            'getLyrics' => ['lyrics' => $this->legacyLyrics($request, $user)],
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }

    private function song(SubsonicRequest $request, User $user): Music
    {
        $decoded = IdCodec::decode($request->require('id'));
        $song = $decoded['type'] === 'song' ? $this->music->find((int) $decoded['value']) : null;
        if ($song === null || !$this->music->isVisibleTo($song, $user)) {
            throw SubsonicException::notFound('Song');
        }

        return $song;
    }

    private function audioFile(SubsonicRequest $request, User $user): Music
    {
        $song = $this->song($request, $user);
        if (!is_file((string) $song->getFile())) {
            throw SubsonicException::notFound('Audio file');
        }

        return $song;
    }

    private function coverArt(SubsonicRequest $request, User $user): Response
    {
        $id = $request->require('id');
        $decoded = IdCodec::decode($id);
        $size = $request->int('size', 0);
        $size = $size > 0 ? min($size, self::MAX_COVER_SIZE) : 0;

        $songId = match ($decoded['type']) {
            'song' => (int) $decoded['value'],
            'album' => $this->library->album($user, (string) $decoded['value'])['cover_song'] ?? null,
            'artist' => $this->artistCover($user, (string) $decoded['value']),
            default => null,
        };
        if ($songId instanceof Response) {
            return $songId;
        }

        $bytes = $songId !== null ? $this->db->fetchOne('SELECT image FROM music WHERE id = ?', [$songId]) : null;
        if (!\is_string($bytes) || $bytes === '') {
            return $this->media->placeholderCover();
        }

        if ($size > 0) {
            $bytes = $this->resized($id, $size, $bytes);
        }

        return $this->media->image($bytes, $request->httpRequest(), 604800);
    }

    /** Artist picture: the profile image if known, else its latest album's cover song. */
    private function artistCover(User $user, string $artistKey): int|Response|null
    {
        $artist = $this->library->artist($user, $artistKey);
        if ($artist === null) {
            return null;
        }
        $imageUrl = $this->profiles->findOneByName($artist['name'])?->getImageUrl();
        if ($imageUrl !== null && str_starts_with($imageUrl, 'https://')) {
            return new RedirectResponse($imageUrl);
        }

        $albums = $this->library->albums($user, 'm.artist_key = :k', ['k' => $artistKey], 'year DESC', 1);

        return $albums[0]['cover_song'] ?? null;
    }

    /**
     * Resized JPEG, cached on disk per (id, size). Without GD the original
     * bytes are returned unchanged.
     */
    private function resized(string $id, int $size, string $bytes): string
    {
        $file = \sprintf('%s/%s-%d.jpg', $this->coverCacheDir, preg_replace('/[^a-z0-9-]/i', '', $id), $size);
        if (is_file($file)) {
            return (string) file_get_contents($file);
        }

        $jpeg = $this->covers->toJpeg($bytes, $size);
        if ($jpeg === null) {
            return $bytes;
        }
        if (!is_dir($this->coverCacheDir)) {
            @mkdir($this->coverCacheDir, 0775, true);
        }
        @file_put_contents($file, $jpeg);

        return $jpeg;
    }

    /** @return list<array<string, mixed>> */
    private function structuredLyrics(Music $song): array
    {
        $text = trim((string) $song->getLyrics());
        if ($text === '') {
            return [];
        }

        $lines = self::parseLrc($text);
        $base = ['displayArtist' => $song->getArtist(), 'displayTitle' => $song->getDisplayTitle(), 'lang' => 'xxx'];
        if ($lines !== []) {
            return [$base + ['synced' => true, 'line' => $lines]];
        }

        return [$base + ['synced' => false, 'line' => array_map(
            static fn(string $l): array => ['value' => $l],
            preg_split('/\R/u', $text) ?: [],
        )]];
    }

    /** @return array<string, mixed> */
    private function legacyLyrics(SubsonicRequest $request, User $user): array
    {
        $artist = (string) $request->get('artist');
        $title = (string) $request->get('title');
        $rows = $this->library->songs($user, 'LOWER(m.artist) = LOWER(:a) AND LOWER(m.title) = LOWER(:t) AND m.lyrics IS NOT NULL', ['a' => $artist, 't' => $title], 'm.id', 1);
        $song = $rows !== [] ? $this->music->find((int) $rows[0]['id']) : null;
        if ($song === null) {
            return ['artist' => $artist, 'title' => $title];
        }

        $text = (string) $song->getLyrics();
        $lines = self::parseLrc($text);
        if ($lines !== []) {
            $text = implode("\n", array_column($lines, 'value'));
        }

        return ['artist' => $song->getArtist(), 'title' => $song->getDisplayTitle(), 'value' => $text];
    }

    /**
     * LRC "[mm:ss.xx] text" → [{start: ms, value}], sorted. Empty when the
     * text has no timestamps (plain lyrics).
     *
     * @return list<array{start: int, value: string}>
     */
    public static function parseLrc(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            if (preg_match_all('/\[(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\]/', $raw, $stamps, \PREG_SET_ORDER) === 0) {
                continue;
            }
            $value = trim((string) preg_replace('/\[[^\]]*\]/', '', $raw));
            foreach ($stamps as $s) {
                $fraction = isset($s[3]) ? (int) str_pad($s[3], 3, '0') : 0;
                $lines[] = ['start' => ((int) $s[1] * 60 + (int) $s[2]) * 1000 + $fraction, 'value' => $value];
            }
        }
        usort($lines, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return $lines;
    }
}
