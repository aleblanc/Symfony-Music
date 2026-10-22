<?php

namespace App\Service\Artist;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use App\Service\Enrichment\MatchScorer;

/**
 * Discography entries (ArtistProfile::getDiscography()) made ready for the
 * album cards of the artist page and of the favorite discography page: the
 * library album each one matches, the tracks of its list already owned, its
 * popularity bar and the "Essential" star.
 */
class DiscographyView
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly MatchScorer $scorer,
        private readonly ArtistProfileRepository $profiles,
    ) {}

    /**
     * "Albums to discover from your artists": for the artists with at least
     * $minSongs tracks in the library (fewer is anecdotal), the albums of
     * their discography not in it, most listened first. One per artist per
     * round (each artist's best missing album, then the second...), so a big
     * artist does not fill the whole row.
     *
     * @param list<string> $hearted release group ids the viewer hearted
     *
     * @return list<array<string, mixed>> cards as build() makes them
     */
    public function albumsToDiscover(?User $viewer, array $hearted, int $minSongs = 11, int $limit = 12): array
    {
        $profiles = $this->profiles->allByName();
        // One query for every artist's albums (the "owned" check), instead of one per artist.
        $libraryAlbums = $this->music->artistAlbums();
        $perArtist = [];
        foreach ($this->music->listArtists($viewer) as $row) {
            $profile = $profiles[$row['artist']] ?? null;
            if ((int) $row['cnt'] < $minSongs || $profile === null || ($profile->getDiscography() ?? []) === []) {
                continue;
            }
            $missing = array_values(array_filter(
                $this->build($profile->getDiscography(), (string) $row['artist'], $viewer, $hearted, true, false, $libraryAlbums[$row['artist']] ?? []),
                static fn(array $d): bool => $d['owned'] === null && ($d['listeners'] ?? 0) > 0,
            ));
            usort($missing, static fn(array $a, array $b): int => $b['listeners'] <=> $a['listeners']);
            if ($missing !== []) {
                $perArtist[] = $missing;
            }
        }

        // Round by round; inside a round, the albums that matter most to their own artist first.
        $out = [];
        for ($round = 0; \count($out) < $limit; ++$round) {
            $picks = array_filter(array_map(static fn(array $albums) => $albums[$round] ?? null, $perArtist));
            if ($picks === []) {
                break;
            }
            usort($picks, static fn(array $a, array $b): int => [$b['popularity'] ?? 0, $b['listeners']] <=> [$a['popularity'] ?? 0, $a['listeners']]);
            array_push($out, ...$picks);
        }

        return \array_slice($out, 0, $limit);
    }

    /**
     * @param list<array<string, mixed>> $entries  discography entries of one artist
     * @param bool                       $rank        popularity bar relative to these entries, star on the most listened
     * @param bool                       $ownedTracks mark the tracks of each list already in the library (one more query)
     * @param ?list<string>              $libraryAlbums the artist's album titles when already known (saves a query)
     * @param list<string>               $hearted  release group ids the viewer hearted
     *
     * @return list<array<string, mixed>> entries + owned (library album name or null), favorite, popularity, top; tracks + have
     */
    public function build(array $entries, string $artist, ?User $viewer, array $hearted, bool $rank = true, bool $ownedTracks = true, ?array $libraryAlbums = null): array
    {
        $albums = $libraryAlbums !== null ? array_map(static fn(string $a): array => ['album' => $a], $libraryAlbums) : $this->music->albumsOfArtist($artist, $viewer);
        $latin = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $key = static fn(string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) ($latin?->transliterate($t) ?: mb_strtolower($t))));
        $have = [];
        foreach ($ownedTracks ? $this->music->findByArtist($artist, $viewer) : [] as $song) {
            $have[$key((string) $song->getTitle())] = true;
        }

        $out = [];
        foreach ($entries as $entry) {
            $owned = null;
            foreach ($albums as $a) {
                if ($this->scorer->similarity((string) $entry['title'], $a['album']) >= 0.85) {
                    $owned = $a['album'];
                    break;
                }
            }
            // Tracks already in the library: same title, case / accents / punctuation ignored.
            if (\is_array($entry['tracklist'] ?? null)) {
                // Track listeners: a bar relative to the album's most listened track, a star on its top 3.
                $listeners = array_filter(array_map(static fn(array $t): int => (int) ($t['listeners'] ?? 0), $entry['tracklist']['tracks']));
                arsort($listeners);
                $stars = \array_slice(array_keys($listeners), 0, min(3, intdiv(\count($listeners), 3)), true);
                $max = $listeners === [] ? 0 : max($listeners);
                foreach ($entry['tracklist']['tracks'] as $i => &$t) {
                    $t['have'] = isset($have[$key((string) $t['title'])]);
                    $t['pop'] = $max > 0 && ($t['listeners'] ?? null) !== null ? (int) round(100 * $t['listeners'] / $max) : null;
                    $t['star'] = \in_array($i, $stars, true);
                }
                unset($t);
            }
            $out[] = $entry + ['artist' => $artist, 'owned' => $owned, 'favorite' => \in_array($entry['id'], $hearted, true), 'popularity' => null, 'top' => false];
        }

        if ($rank) {
            // ListenBrainz listeners: a bar relative to the most listened album, a star on the top 3 (at most half).
            $listeners = array_filter(array_map(static fn(array $d): int => (int) ($d['listeners'] ?? 0), $out));
            arsort($listeners);
            $top = \array_slice(array_keys($listeners), 0, min(3, intdiv(\count($listeners) + 1, 2)), true);
            $max = $listeners === [] ? 0 : max($listeners);
            foreach ($out as $i => &$d) {
                $d['popularity'] = $max > 0 ? (int) round(100 * (int) ($d['listeners'] ?? 0) / $max) : null;
                $d['top'] = \in_array($i, $top, true);
            }
            unset($d);
        }

        return $out;
    }
}
