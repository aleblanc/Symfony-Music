<?php

namespace App\Service\Spotify;

/**
 * Decides whether a Spotify track is the same recording as a library song.
 *
 * Tags are often noisy ("Pennywise - Fight Till You Die - 2005 Remaster" with
 * an "Unknown Artist"), so titles/artists are cleaned first, then each
 * candidate is scored on title, artist and duration similarity. Only a
 * confident match counts: a missing link is better than a wrong one.
 */
class SpotifyMatcher
{
    public const MIN_SCORE = 0.75;
    private const UNKNOWN_ARTISTS = ['', 'unknown artist', 'unknown', 'various artists', 'artiste inconnu'];

    /**
     * Cleaned search title and artist list. When the artist tag is unknown
     * and the title looks like "Artist - Title", the artist is taken from it.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function searchTerms(string $title, string $artist): array
    {
        $artists = $this->splitArtists($artist);
        if ($artists === [] && preg_match('/^(.+?)\s+-\s+(.+)$/u', $title, $m)) {
            $artists = $this->splitArtists($m[1]);
            $title = $m[2];
        }
        // "Pennywise- California Dreaming": the artist repeated as a title prefix.
        foreach ($artists as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . '\s*[-–:]\s*(.+)$/iu', $title, $m)) {
                $title = $m[1];
                break;
            }
        }

        return [$this->cleanTitle($title), $artists];
    }

    /**
     * Similarity of a candidate (0..1), or null when it is clearly not the
     * same song (title or artist too different).
     *
     * @param list<string> $artists
     */
    public function score(string $title, array $artists, ?int $duration, SpotifyTrack $candidate): ?float
    {
        $titleSim = $this->similarity($this->normalize($title), $this->normalize($this->cleanTitle($candidate->name)));

        $durationSim = 0.5; // unknown duration: neutral
        if ($duration !== null && $duration > 0 && $candidate->durationSeconds !== null) {
            $diff = abs($duration - $candidate->durationSeconds);
            $durationSim = match (true) {
                $diff <= 3 => 1.0,
                $diff <= 10 => 0.7,
                $diff <= 30 => 0.3,
                default => 0.0,
            };
        }

        if ($artists === []) {
            // Without an artist only a near-exact title with a matching length is trusted.
            if ($titleSim < 0.9 || $durationSim < 0.7) {
                return null;
            }

            return 0.7 * $titleSim + 0.3 * $durationSim;
        }

        $artistSim = 0.0;
        foreach ($artists as $ours) {
            foreach ($candidate->artists as $theirs) {
                $artistSim = max($artistSim, $this->similarity($this->normalize($ours), $this->normalize($theirs)));
            }
        }
        if ($titleSim < 0.75 || $artistSim < 0.7) {
            return null;
        }

        return 0.5 * $titleSim + 0.35 * $artistSim + 0.15 * $durationSim;
    }

    /**
     * Strips version noise that Spotify titles format differently:
     * "(Remastered 2011)", " - 2005 Remaster", "(feat. X)", "[Official Video]"…
     */
    public function cleanTitle(string $title): string
    {
        $noise = 'remaster|remastered|live|version|edit|mono|stereo|mix|feat\.?|ft\.?|featuring|official|video|audio|lyrics?|explicit|bonus|deluxe|clip';
        $title = preg_replace('/\s*[\(\[][^)\]]*\b(' . $noise . ')\b[^)\]]*[\)\]]/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+-\s+(\d{4}\s+)?(remaster(ed)?|live|radio edit|single version|album version|mono|stereo|bonus track)\b.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+(feat\.?|ft\.?|featuring)\s+.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/^\d{1,2}\s*[-.]\s+/u', '', $title) ?? $title; // "01 - Title"

        return trim($title);
    }

    /**
     * @return list<string>
     */
    private function splitArtists(string $artist): array
    {
        if (\in_array(mb_strtolower(trim($artist)), self::UNKNOWN_ARTISTS, true)) {
            return [];
        }
        $parts = preg_split('/\s*(?:,|;|\/|\s&\s|\sand\s|\bfeat\.?\s|\bft\.?\s|\bfeaturing\s|\bvs\.?\s)\s*/iu', $artist) ?: [$artist];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Transliterator::class)) {
            $s = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($s) ?: $s;
        }
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/^the\s+/', '', $s) ?? $s;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
    }

    /** 0..1 similarity of two raw strings (accents, case and punctuation ignored). */
    public function looselyEquals(string $a, string $b): float
    {
        return $this->similarity($this->normalize($a), $this->normalize($b));
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $a = mb_substr($a, 0, 200);
        $b = mb_substr($b, 0, 200);
        // One side merely adds a word or two ("blink 182" vs "blink 182 band"):
        // close, not identical. A short title inside a long one ("pennywise" in
        // "pennywise california dreaming") is a different song.
        $shorter = min(\strlen($a), \strlen($b));
        if ($shorter >= 4 && $shorter / max(\strlen($a), \strlen($b)) >= 0.6 && (str_contains($a, $b) || str_contains($b, $a))) {
            return 0.9;
        }

        return 1 - levenshtein($a, $b) / max(\strlen($a), \strlen($b));
    }
}
