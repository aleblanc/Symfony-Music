<?php

namespace App\Service\Artist;

/**
 * Finds the spellings of one artist ("Blink 182", "Blink182", "blink‐182";
 * "Offspring" / "The Offspring"; case differences...) and picks the name to
 * keep. Pure: fed with (artist, count) rows, used by `app:artist:merge`.
 *
 * Two names are the same artist when ArtistInfoFetcher::normalize() makes
 * them equal (case, accents, spaces, punctuation and a leading "The" ignored).
 */
class ArtistMerger
{
    /**
     * @param array<string, int> $trackCounts artist name => number of tracks
     *
     * @return list<array{key: string, canonical: string, variants: array<string, int>}> only groups with several spellings, biggest first
     */
    public function groups(array $trackCounts): array
    {
        $byKey = [];
        foreach ($trackCounts as $name => $count) {
            $name = (string) $name;
            $key = ArtistInfoFetcher::normalize($name);
            if ($key !== '') {
                $byKey[$key][$name] = $count;
            }
        }

        $groups = [];
        foreach ($byKey as $key => $variants) {
            if (\count($variants) < 2) {
                continue;
            }
            $groups[] = ['key' => (string) $key, 'canonical' => $this->canonical($variants), 'variants' => $variants];
        }
        usort($groups, static fn(array $a, array $b) => [array_sum($b['variants']), $a['key']] <=> [array_sum($a['variants']), $b['key']]);

        return $groups;
    }

    /**
     * The spelling to keep: the most used one; on a tie, the one with a
     * leading "The", then capitals, then spaces ("The Offspring", "Sum 41"),
     * then alphabetical, so the choice is stable.
     *
     * @param array<string, int> $variants
     */
    public function canonical(array $variants): string
    {
        $names = array_map('strval', array_keys($variants));
        usort($names, static fn(string $a, string $b) => [
            $variants[$b],
            (int) (stripos($b, 'the ') === 0),
            (int) (preg_match('/\p{Lu}/u', $b) === 1),
            substr_count($b, ' '),
            $a,
        ] <=> [
            $variants[$a],
            (int) (stripos($a, 'the ') === 0),
            (int) (preg_match('/\p{Lu}/u', $a) === 1),
            substr_count($a, ' '),
            $b,
        ]);

        return $names[0];
    }
}
