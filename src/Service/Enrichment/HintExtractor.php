<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

/**
 * Turns a track's path and current tags into {@see TrackHints}.
 *
 * Pure string work, no I/O: tags come from the indexed `music` row (the
 * scanner already falls back to the file name for a missing title and to
 * "Unknown Artist"/"Unknown Album"), so those fallbacks are recognised and
 * discarded here rather than mistaken for real tags.
 */
final class HintExtractor
{
    /** Folder names that never describe an artist or album. */
    private const NOISE_SEGMENTS = [
        'downloads', 'download', 'téléchargements', 'telechargements', 'music', 'musique', 'musics', 'my music',
        'mp3', 'mp3s', 'flac', 'audio', 'misc', 'divers', 'various', 'unsorted', 'new folder', 'nouveau dossier',
        'albums', 'singles', 'tmp', 'temp', 'itunes', 'itunes media', 'itunes music', 'library', 'compilations',
        'torrents', 'incoming', 'a trier', 'à trier', 'old', 'new', 'backup',
        // Windows Media Player / iTunes defaults for untagged rips.
        'album inconnu', 'artiste inconnu', 'non spécifié', 'non specifie', 'unknown album', 'unknown artist', 'various artists',
        'interprète inconnu', 'interprete inconnu',
    ];

    /** Folder levels kept as hints for a file outside the library root. */
    private const OUTSIDE_ROOT_LEVELS = 3;

    private const UNKNOWN_VALUES = ['', 'unknown', 'unknown artist', 'unknown album', 'unknown genre', 'artist', 'album', 'inconnu', 'artiste inconnu', 'album inconnu', 'non spécifié', 'non specifie', 'interprète inconnu', 'interprete inconnu'];

    /**
     * @param ?int $knownTrackNumber track number already indexed from the tags, preferred over the file name
     */
    public function extract(string $filePath, string $musicDir, ?string $title, ?string $artist, ?string $album, int $duration, ?int $knownTrackNumber = null): TrackHints
    {
        // macOS file names are decomposed (NFD); compare everything composed.
        [$filePath, $musicDir, $title, $artist, $album] = array_map(self::nfc(...), [$filePath, $musicDir, $title, $artist, $album]);
        $stem = pathinfo($filePath, \PATHINFO_FILENAME);
        [$segments, $segmentDisc, $year] = $this->parseDirectories($filePath, $musicDir);
        $tagArtist = $this->known($artist);
        [$disc, $track, $fileArtist, $fileTitle, $placeholderNumber] = $this->parseFilename($stem, array_filter([$tagArtist, ...$segments]));

        $title = $this->clean($title ?? '');
        // The scanner stores the file name as title when the tag is missing.
        $tagTitle = $this->isPlaceholderTitle($title) || $title === $this->clean($stem) ? null : $title;
        if ($this->isPlaceholderTitle($title)) {
            $track ??= $this->placeholderNumber($title);
        }

        return new TrackHints(
            tagTitle: $tagTitle,
            tagArtist: $tagArtist,
            tagAlbum: $this->known($album),
            titleIsPlaceholder: $tagTitle === null && $fileTitle === null,
            filenameTitle: $fileTitle,
            filenameArtist: $fileArtist,
            trackNumber: $knownTrackNumber ?? $track ?? $placeholderNumber,
            discNumber: $disc ?? $segmentDisc,
            yearHint: $year,
            pathSegments: $segments,
            duration: $duration,
        );
    }

    /**
     * True for titles that carry no information: "Track 1", "Piste 03",
     * "05", "Untitled", empty...
     */
    public function isPlaceholderTitle(?string $title): bool
    {
        $title = trim((string) $title);

        return $title === '' || preg_match(
            '/^(?:(?:audio\s*)?track|piste(?:\s*audio)?|titre|pista|traccia|titel|untitled|unknown|sans\s*titre|morceau|audio)?[\s_\-.#]*(?:\d{1,3}[a-z]?)?$/iu',
            $title,
        ) === 1;
    }

    /**
     * @param array<string> $knownNames tag artist and folder names, used to split "artist-title" on a bare hyphen
     *
     * @return array{0: ?int, 1: ?int, 2: ?string, 3: ?string, 4: ?int} disc, track, artist, title, number found in a placeholder
     */
    private function parseFilename(string $stem, array $knownNames): array
    {
        // Scene releases ("13-artist_name-song_title-grp") use underscores
        // for spaces and end with a release-group tag.
        $scene = str_contains($stem, '_');
        $stem = trim(str_replace('_', ' ', $stem));
        // Copies made by the OS: "Song(1)", "Song (2)", "Song - Copie", "Song - Copy (3)".
        $stem = trim((string) preg_replace('/\s*-\s*(?:copie|copy)(?:\s*\(?\d+\)?)?$|\s*\(\d{1,2}\)$/iu', '', $stem));
        $disc = $track = null;
        $rest = $stem;

        if (preg_match('/^(\d)[.\-](\d{2})[\s.\-]+(.+)$/u', $stem, $m) === 1) {
            [$disc, $track, $rest] = [(int) $m[1], (int) $m[2], $m[3]];
        } elseif (preg_match('/^(\d{1,3})$/', $stem, $m) === 1) {
            return [null, (int) $m[1], null, null, null];
        } elseif (preg_match('/^(\d{1,3})(\s*[.\-)]\s*|\s+)(.+)$/u', $stem, $m) === 1) {
            // "50 Cent - X" / "99 Problems": a bare number followed by a
            // plain space is only a track number when zero-padded or low
            // enough to be a plausible position ("10 Forgotten").
            if (str_starts_with($m[1], '0') || trim($m[2]) !== '' || (int) $m[1] <= 30) {
                [$track, $rest] = [(int) $m[1], $m[3]];
            }
        }

        $rest = $this->clean($rest);
        if ($this->isPlaceholderTitle($rest)) {
            return [$disc, $track, null, null, $this->placeholderNumber($rest)];
        }

        // "Artist - Album - 07 - Title", "Game OST - Artist - Title"...
        $parts = array_values(array_filter(
            array_map('trim', preg_split(TrackHints::DASH_SEPARATOR, $rest) ?: [$rest]),
            static fn(string $p): bool => $p !== '',
        ));
        if (\count($parts) >= 2) {
            foreach ($parts as $i => $part) {
                if ($i < \count($parts) - 1 && preg_match('/^\d{1,3}$/', $part) === 1) {
                    $track ??= (int) $part;
                    unset($parts[$i]);
                }
            }
            $parts = array_values($parts);
            if (\count($parts) === 1) {
                return [$disc, $track, null, $parts[0], null];
            }
            $artistIndex = 0;
            foreach ($parts as $i => $part) {
                if ($this->matchesAny($part, $knownNames)) {
                    $artistIndex = $i;
                    break;
                }
            }
            $titleIndex = \count($parts) - 1;
            if ($titleIndex === $artistIndex) {
                --$titleIndex;
            }

            return [$disc, $track, $parts[$artistIndex], $parts[$titleIndex], null];
        }

        // "coldplay-paradise": a bare hyphen only splits when the left part
        // names a known artist, so hyphenated titles stay intact.
        $hyphen = strpos($rest, '-');
        if ($hyphen !== false) {
            $left = trim(substr($rest, 0, $hyphen));
            $right = trim(substr($rest, $hyphen + 1));
            if ($right !== '' && $this->matchesAny($left, $knownNames)) {
                if ($scene) {
                    $right = preg_replace('/-[a-z0-9]{2,4}$/i', '', $right) ?? $right;
                }

                return [$disc, $track, $left, trim($right), null];
            }
        }

        return [$disc, $track, null, $rest, null];
    }

    /**
     * @param array<string> $names
     */
    private function matchesAny(string $value, array $names): bool
    {
        $key = static fn(string $v): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($v)) ?? '';
        $value = $key($value);
        foreach ($names as $name) {
            if ($value !== '' && $value === $key($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: ?int, 2: ?int} cleaned segments (nearest first), disc number, release year
     */
    private function parseDirectories(string $filePath, string $musicDir): array
    {
        $dir = \dirname(str_replace('\\', '/', $filePath));
        $root = rtrim(str_replace('\\', '/', $musicDir), '/');
        $inside = $root !== '' && str_starts_with($dir . '/', $root . '/');
        $parts = array_values(array_filter(explode('/', $inside ? substr($dir, \strlen($root)) : $dir), static fn(string $s): bool => $s !== ''));
        // Outside the library root (import folder, MUSIC_DIR changed...), only
        // the last levels (artist/album/disc) can describe the music: never
        // "Users", the account name or "Volumes".
        if (!$inside) {
            $parts = \array_slice($parts, -self::OUTSIDE_ROOT_LEVELS);
        }

        $segments = [];
        $disc = $year = null;
        foreach (array_reverse($parts) as $raw) {
            $value = trim(str_replace('_', ' ', $raw));
            if (preg_match('/^(?:cd|disc|disk|disque|dvd)\s*(\d{1,2})$/iu', $value, $m) === 1) {
                $disc ??= (int) $m[1];
                continue;
            }
            // A bare year folder is usually the download year, not the release.
            if (preg_match('/^(?:19|20)\d{2}$/', $value) === 1) {
                continue;
            }
            if (preg_match('/[(\[]((?:19|20)\d{2})[)\]]/', $value, $m) === 1) {
                $year ??= (int) $m[1];
            }
            $value = $this->clean($value);
            if ($value === '' || \in_array(mb_strtolower($value), self::NOISE_SEGMENTS, true)
                || preg_match('/^www\.|\.(?:com|net|org|biz|info|ru|fr|to|cc|me|io)$/i', $value) === 1) {
                continue;
            }
            $segments[] = $value;
        }

        return [$segments, $disc, $year];
    }

    /** Strips bracketed noise ("[320kbps]", "(Official Video)", "(2001)"), underscores and extra spaces. */
    private function clean(string $value): string
    {
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/\[[^\]]*\]|\{[^}]*\}/u', ' ', $value) ?? $value;
        // Download-site stamps: "www.mp3lio.net", "(shuvosky.com)".
        $value = preg_replace('/\(?\s*(?:www\.)?[\w-]+\.(?:com|net|org|biz|info|us|ru|fr|to|cc)\b\s*\)?|\bwww\.\S+/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\((?:(?:19|20)\d{2}|[^)]*\b(?:official|video|clip|lyrics?|audio|hd|hq|remaster(?:ed)?\s*\d*|kbps|explicit|visualizer|ost|soundtrack|trailer|trejler)\b[^)]*)\)/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{3}\s*kbps\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B-–—.");
    }

    /** @return ($value is null ? null : string) */
    public static function nfc(?string $value): ?string
    {
        if ($value === null || !class_exists(\Normalizer::class)) {
            return $value;
        }

        return \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
    }

    private function known(?string $value): ?string
    {
        $value = $this->clean((string) $value);
        // Duplicated rip credits such as "Coldplay/Coldplay" (but keep "AC/DC").
        $parts = array_map('trim', explode('/', $value));
        if (\count($parts) > 1 && \count(array_unique(array_map('mb_strtolower', $parts))) === 1) {
            $value = $parts[0];
        }

        return \in_array(mb_strtolower($value), self::UNKNOWN_VALUES, true) ? null : $value;
    }

    private function placeholderNumber(string $value): ?int
    {
        return preg_match('/(\d{1,3})[a-z]?\s*$/i', $value, $m) === 1 ? (int) $m[1] : null;
    }
}
