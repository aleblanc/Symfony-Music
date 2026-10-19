<?php

namespace App\Service\Spotify;

use App\Entity\Music;

/**
 * Finds a song's exact open.spotify.com track without any Spotify account,
 * from {@see PublicCatalog} sources, cheapest first:
 *
 *  1. Wikidata track ids + the artist's top 10 (embed page)
 *  2. the tracks of the album named in the tags (Wikidata or MusicBrainz → embed)
 *  3. the tracks of the artist's other albums known to Wikidata
 *
 * Coverage is partial (well-documented artists only); everything fetched is
 * cached per artist/album for the run, so later songs of the same artist are
 * almost free.
 */
class SpotifyResolver
{
    private const MAX_ARTISTS_PER_SONG = 2;
    private const MAX_ALBUMS_PER_ARTIST = 25;

    /** @var array<string, array{mbid: string, albums: list<array{title: string, id: string}>, tracks: list<SpotifyTrack>}|null> */
    private array $artists = [];

    /** @var array<string, list<SpotifyTrack>> Spotify album id => tracks */
    private array $albumTracks = [];

    /** @var array<string, list<string>> "mbid|album" => Spotify album ids */
    private array $mbAlbums = [];

    public function __construct(
        private readonly PublicCatalog $catalog,
        private readonly SpotifyMatcher $matcher,
    ) {}

    /**
     * @param ?callable(string):void $log progress messages (fetches)
     *
     * @throws SpotifyException when Spotify keeps rate-limiting
     */
    public function resolve(Music $song, float $minScore = SpotifyMatcher::MIN_SCORE, ?callable $log = null): ?SpotifyMatch
    {
        $log ??= static function (): void {};
        [$title, $artists] = $this->matcher->searchTerms($song->getDisplayTitle(), (string) $song->getArtist());
        if ($title === '' || $artists === []) {
            return null; // without an artist there is no catalogue to look into
        }
        $album = trim((string) $song->getAlbum());
        $album = \in_array(mb_strtolower($album), ['', 'unknown album', 'uploaded'], true) ? null : $album;

        foreach (\array_slice($artists, 0, self::MAX_ARTISTS_PER_SONG) as $artistName) {
            $artist = $this->artist($artistName, $log);
            if ($artist === null) {
                continue;
            }
            if ($match = $this->best($title, $artists, $song->getDuration(), $artist['tracks'], $minScore, 'wikidata / top tracks')) {
                return $match;
            }

            $albumIds = [];
            if ($album !== null) {
                foreach ($artist['albums'] as $known) {
                    if ($this->matcher->looselyEquals($this->matcher->cleanTitle($known['title']), $this->matcher->cleanTitle($album)) >= 0.85) {
                        $albumIds[] = $known['id'];
                    }
                }
                if ($albumIds === []) {
                    $albumIds = $this->musicBrainzAlbums($artist['mbid'], $album, $log);
                }
                foreach ($albumIds as $albumId) {
                    if ($match = $this->best($title, $artists, $song->getDuration(), $this->album($albumId, $log), $minScore, 'album "' . $album . '"')) {
                        return $match;
                    }
                }
            }

            foreach (\array_slice($artist['albums'], 0, self::MAX_ALBUMS_PER_ARTIST) as $known) {
                if (!\in_array($known['id'], $albumIds, true)
                    && ($match = $this->best($title, $artists, $song->getDuration(), $this->album($known['id'], $log), $minScore, 'album "' . $known['title'] . '"'))) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string>       $artists
     * @param list<SpotifyTrack> $tracks
     */
    private function best(string $title, array $artists, ?int $duration, array $tracks, float $minScore, string $source): ?SpotifyMatch
    {
        $best = null;
        foreach ($tracks as $track) {
            $score = $this->matcher->score($title, $artists, $duration, $track);
            if ($score !== null && $score >= $minScore && ($best === null || $score > $best->score)) {
                $best = new SpotifyMatch($track, $score, $source);
            }
        }

        return $best;
    }

    /**
     * @param callable(string):void $log
     *
     * @return array{mbid: string, albums: list<array{title: string, id: string}>, tracks: list<SpotifyTrack>}|null
     */
    private function artist(string $name, callable $log): ?array
    {
        $key = mb_strtolower($name);
        if (\array_key_exists($key, $this->artists)) {
            return $this->artists[$key];
        }

        $log(\sprintf('looking up artist "%s"', $name));
        $refs = $this->catalog->findArtist($name);
        if ($refs === null) {
            return $this->artists[$key] = null;
        }

        $works = $refs['wikidata'] !== null ? $this->catalog->wikidataWorks($refs['wikidata'], $refs['name']) : ['albums' => [], 'tracks' => []];
        $tracks = $works['tracks'];
        if ($refs['spotify'] !== null) {
            $tracks = array_merge($tracks, $this->catalog->embedTracks('artist', $refs['spotify']));
        }

        return $this->artists[$key] = ['mbid' => $refs['mbid'], 'albums' => $works['albums'], 'tracks' => $tracks];
    }

    /**
     * @param callable(string):void $log
     *
     * @return list<SpotifyTrack>
     */
    private function album(string $spotifyAlbumId, callable $log): array
    {
        if (!isset($this->albumTracks[$spotifyAlbumId])) {
            $log('reading album ' . $spotifyAlbumId);
            $this->albumTracks[$spotifyAlbumId] = $this->catalog->embedTracks('album', $spotifyAlbumId);
        }

        return $this->albumTracks[$spotifyAlbumId];
    }

    /**
     * @param callable(string):void $log
     *
     * @return list<string>
     */
    private function musicBrainzAlbums(string $artistMbid, string $album, callable $log): array
    {
        $key = $artistMbid . '|' . mb_strtolower($album);
        if (!isset($this->mbAlbums[$key])) {
            $log(\sprintf('looking up album "%s" on MusicBrainz', $album));
            $this->mbAlbums[$key] = $this->catalog->musicBrainzAlbumIds($artistMbid, $album);
        }

        return $this->mbAlbums[$key];
    }
}
