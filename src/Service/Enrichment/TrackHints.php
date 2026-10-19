<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/**
 * Weak identification hints gathered for one track from its path, file name
 * and current tags. Every field is optional: the library layout is arbitrary,
 * so nothing here is trusted until a remote source confirms it.
 */
final readonly class TrackHints
{
    /**
     * @param list<string> $pathSegments cleaned folder names, nearest folder first
     */
    public function __construct(
        public ?string $tagTitle,
        public ?string $tagArtist,
        public ?string $tagAlbum,
        public bool $titleIsPlaceholder,
        public ?string $filenameTitle,
        public ?string $filenameArtist,
        public ?int $trackNumber,
        public ?int $discNumber,
        public ?int $yearHint,
        public array $pathSegments,
        public int $duration,
    ) {}

    /** Best guess for the track title, or null when nothing usable exists. */
    public function bestTitle(): ?string
    {
        return $this->tagTitle ?? $this->filenameTitle;
    }

    public function bestArtist(): ?string
    {
        return $this->tagArtist ?? $this->filenameArtist;
    }

    /**
     * Most likely artist: tags/file name first, then the left side of an
     * "Artist - Album" folder, then the outermost folder (layouts that do
     * name the artist put it above the album).
     */
    public function artistGuess(): ?string
    {
        $artist = $this->bestArtist();
        if ($artist !== null) {
            return $artist;
        }
        foreach ($this->pathSegments as $segment) {
            $parts = self::splitArtistDash($segment);
            if ($parts !== null) {
                return $parts[0];
            }
        }

        return $this->pathSegments === [] ? null : $this->pathSegments[\count($this->pathSegments) - 1];
    }

    /**
     * (artist, album) combinations worth a MusicBrainz release search, most
     * trustworthy first. The artist may be null for an album-only search.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    public function albumSearchPairs(): array
    {
        $pairs = [];
        $nearest = $this->pathSegments[0] ?? null;

        if ($this->tagArtist !== null && $this->tagAlbum !== null) {
            $pairs[] = [$this->tagArtist, $this->tagAlbum];
        }
        foreach ($this->pathSegments as $segment) {
            $parts = self::splitArtistDash($segment);
            if ($parts !== null) {
                $pairs[] = $parts;
                break;
            }
        }
        if (isset($this->pathSegments[1]) && $nearest !== null) {
            $pairs[] = [$this->pathSegments[1], $nearest];
        }
        if ($this->tagArtist !== null && $nearest !== null) {
            $pairs[] = [$this->tagArtist, self::splitArtistDash($nearest)[1] ?? $nearest];
        }
        if ($this->tagAlbum !== null) {
            $pairs[] = [null, $this->tagAlbum];
        }
        if ($nearest !== null) {
            $pairs[] = [null, self::splitArtistDash($nearest)[1] ?? $nearest];
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[mb_strtolower(($pair[0] ?? '') . "\0" . $pair[1])] ??= $pair;
        }

        return \array_slice(array_values($unique), 0, 4);
    }

    /**
     * Words describing this track (hints, segments), used for name similarity.
     *
     * @return list<string>
     */
    public function nameCandidates(): array
    {
        $names = array_filter([$this->tagArtist, $this->tagAlbum, $this->filenameArtist]);
        foreach ($this->pathSegments as $segment) {
            $names[] = $segment;
            $parts = self::splitArtistDash($segment);
            if ($parts !== null) {
                $names[] = $parts[0];
                $names[] = $parts[1];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function splitArtistDash(string $value): ?array
    {
        $parts = preg_split('/\s+[-–—]\s+/u', $value);
        if ($parts === false || \count($parts) < 2) {
            return null;
        }
        $first = trim($parts[0]);
        $last = trim((string) end($parts));

        return $first !== '' && $last !== '' ? [$first, $last] : null;
    }
}
