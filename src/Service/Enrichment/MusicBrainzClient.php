<?php

declare(strict_types=1);

namespace App\Service\Enrichment;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal MusicBrainz web-service (v2, JSON) client.
 *
 * MusicBrainz asks for at most one request per second and an identifying
 * User-Agent; both are enforced here. Responses are cached (releases and
 * recordings barely change) so re-runs cost no requests.
 *
 * @see https://musicbrainz.org/doc/MusicBrainz_API
 */
class MusicBrainzClient
{
    private const BASE_URL = 'https://musicbrainz.org/ws/2/';
    private const MIN_INTERVAL = 1.05;
    private const MAX_ATTEMPTS = 6;
    private const MAX_BACKOFF = 30;
    /** Seconds without data before a request is abandoned (and retried). */
    public const IDLE_TIMEOUT = 20;
    /** Hard cap per request: a laptop going to sleep once left one hanging 17 minutes. */
    public const MAX_DURATION = 60;
    private const CACHE_TTL = 30 * 86400;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * @return list<ReleaseSummary>
     */
    public function searchReleases(?string $artist, string $album, int $limit = 5): array
    {
        $query = 'release:' . $this->quote($album) . ($artist !== null ? ' AND artist:' . $this->quote($artist) : '');
        $data = $this->get('release', ['query' => $query, 'limit' => $limit]);

        return array_values(array_map(fn(array $r): ReleaseSummary => $this->releaseSummary($r), $data['releases'] ?? []));
    }

    /**
     * @return list<array{id: string, name: string, score: int, disambiguation: ?string, aliases: list<string>}>
     */
    public function searchArtists(string $name, int $limit = 5): array
    {
        $data = $this->get('artist', ['query' => 'artist:' . $this->quote($name), 'limit' => $limit]);

        return array_values(array_map(static fn(array $a): array => [
            'id' => (string) ($a['id'] ?? ''),
            'name' => (string) ($a['name'] ?? ''),
            'score' => (int) ($a['score'] ?? 0),
            'disambiguation' => isset($a['disambiguation']) && $a['disambiguation'] !== '' ? (string) $a['disambiguation'] : null,
            'aliases' => array_values(array_filter(array_map(static fn(array $al): string => (string) ($al['name'] ?? ''), $a['aliases'] ?? []))),
        ], $data['artists'] ?? []));
    }

    /**
     * Artists credited on the release groups titled $title by an artist
     * named $artist: names a homonym (the French band "Luke" is far behind
     * Laidback Luke and the US rapper in a plain artist search).
     *
     * @return list<array{id: string, name: string, disambiguation: ?string}>
     */
    public function searchReleaseGroupArtists(string $artist, string $title, int $limit = 5): array
    {
        $data = $this->get('release-group', ['query' => 'releasegroup:' . $this->quote($title) . ' AND artist:' . $this->quote($artist), 'limit' => $limit]);
        $artists = [];
        foreach ($data['release-groups'] ?? [] as $group) {
            foreach ($group['artist-credit'] ?? [] as $credit) {
                $a = $credit['artist'] ?? null;
                if (\is_array($a) && isset($a['id'], $a['name'])) {
                    $artists[] = [
                        'id' => (string) $a['id'],
                        'name' => (string) $a['name'],
                        'disambiguation' => isset($a['disambiguation']) && $a['disambiguation'] !== '' ? (string) $a['disambiguation'] : null,
                    ];
                }
            }
        }

        return $artists;
    }

    /**
     * The artist's URL relations (Wikidata, Wikipedia, YouTube, official homepage...).
     *
     * @return list<array{type: string, url: string}>
     */
    public function getArtistUrls(string $mbid): array
    {
        $data = $this->get('artist/' . rawurlencode($mbid), ['inc' => 'url-rels']);
        $urls = [];
        foreach ($data['relations'] ?? [] as $rel) {
            $url = $rel['url']['resource'] ?? null;
            if (\is_string($url) && ($rel['ended'] ?? false) !== true) {
                $urls[] = ['type' => (string) ($rel['type'] ?? ''), 'url' => $url];
            }
        }

        return $urls;
    }

    /**
     * Titles of the artist's release groups (albums, singles...), to tell
     * homonymous artists apart by what the library holds.
     *
     * @return list<string>
     */
    public function getArtistReleaseGroupTitles(string $mbid): array
    {
        $data = $this->get('release-group', ['artist' => $mbid, 'limit' => 100]);

        return array_values(array_map(static fn(array $g): string => (string) ($g['title'] ?? ''), $data['release-groups'] ?? []));
    }

    /**
     * @param bool $excludeLive drop recordings whose disambiguation says "live"
     *                          (popular songs have dozens of live takes)
     *
     * @return list<RecordingMatch>
     */
    /**
     * The artist's studio albums and EPs (release groups without a secondary
     * type: no compilation, remix, demo...), plus live albums and soundtracks
     * (Live or Soundtrack as the only secondary type, typed "Live" /
     * "Soundtrack"), newest first, undated ones last.
     *
     * @return list<array{id: string, title: string, type: string, date: ?string}>
     */
    public function getArtistAlbums(string $mbid): array
    {
        $groups = [];
        for ($offset = 0; $offset < 500; $offset += 100) {
            $data = $this->get('release-group', ['artist' => $mbid, 'type' => 'album|ep', 'limit' => 100, 'offset' => $offset]);
            $page = $data['release-groups'] ?? [];
            foreach ($page as $g) {
                $secondary = $g['secondary-types'] ?? [];
                $kept = \count($secondary) === 1 && \in_array($secondary[0], ['Live', 'Soundtrack'], true) ? $secondary[0] : null;
                if (($secondary !== [] && $kept === null) || !isset($g['id'], $g['title'])) {
                    continue;
                }
                $date = (string) ($g['first-release-date'] ?? '');
                $groups[] = [
                    'id' => (string) $g['id'],
                    'title' => (string) $g['title'],
                    'type' => $kept ?? (string) ($g['primary-type'] ?? 'Album'),
                    'date' => $date !== '' ? $date : null,
                ];
            }
            if (\count($page) < 100 || $offset + 100 >= (int) ($data['release-group-count'] ?? 0)) {
                break;
            }
        }
        usort($groups, static fn(array $a, array $b): int => [$b['date'] !== null, $b['date']] <=> [$a['date'] !== null, $a['date']]);

        return $groups;
    }

    /**
     * Track list of an album: its original edition (the earliest dated
     * official release), one request for every edition with its tracks.
     *
     * @return array{release: string, date: ?string, country: ?string, format: ?string, tracks: list<array{n: string, title: string, length: ?int, recording: ?string}>}|null null when the album has no official release
     */
    public function getReleaseGroupTracks(string $releaseGroupId): ?array
    {
        $data = $this->get('release', ['release-group' => $releaseGroupId, 'inc' => 'recordings+media', 'status' => 'official', 'limit' => 25]);
        $releases = array_values(array_filter($data['releases'] ?? [], static fn(array $r): bool => ($r['media'] ?? []) !== []));
        if ($releases === []) {
            return null;
        }
        // Earliest year first, a full date before a bare year ("1997-04-22" before "1997"); undated editions last.
        $key = static fn(array $r): array => [($r['date'] ?? '') === '', substr($r['date'] ?? '', 0, 4), -\strlen($r['date'] ?? ''), $r['date'] ?? ''];
        usort($releases, static fn(array $a, array $b): int => $key($a) <=> $key($b));
        $release = $releases[0];
        $media = $release['media'];

        $tracks = [];
        foreach ($media as $disc) {
            foreach ($disc['tracks'] ?? [] as $t) {
                $n = (string) ($t['number'] ?? $t['position'] ?? '');
                $tracks[] = [
                    'n' => \count($media) > 1 ? ($disc['position'] ?? '?') . '-' . $n : $n,
                    'title' => (string) ($t['title'] ?? ''),
                    'length' => isset($t['length']) ? (int) round($t['length'] / 1000) : null,
                    'recording' => isset($t['recording']['id']) ? (string) $t['recording']['id'] : null,
                ];
            }
        }

        return [
            'release' => (string) $release['id'],
            'date' => isset($release['date']) && $release['date'] !== '' ? (string) $release['date'] : null,
            'country' => $release['country'] ?? null,
            'format' => implode(' + ', array_unique(array_filter(array_map(static fn(array $d) => $d['format'] ?? null, $media)))) ?: null,
            'tracks' => $tracks,
        ];
    }

    public function searchRecordings(?string $artist, string $title, int $limit = 5, bool $excludeLive = false): array
    {
        $query = 'recording:' . $this->quote($title) . ($artist !== null ? ' AND artist:' . $this->quote($artist) : '')
            . ($excludeLive ? ' AND NOT comment:live' : '');
        $data = $this->get('recording', ['query' => $query, 'limit' => $limit]);

        return array_values(array_map(fn(array $r): RecordingMatch => $this->recording($r), $data['recordings'] ?? []));
    }

    public function getRecording(string $id): ?RecordingMatch
    {
        $data = $this->get('recording/' . rawurlencode($id), ['inc' => 'releases+release-groups+artist-credits']);

        return isset($data['id']) ? $this->recording($data) : null;
    }

    public function getRelease(string $id): ?ReleaseCandidate
    {
        $data = $this->get('release/' . rawurlencode($id), ['inc' => 'recordings+artist-credits+release-groups+genres']);
        if (!isset($data['id'])) {
            return null;
        }

        $albumArtist = $this->artistCredit($data['artist-credit'] ?? []);
        $tracks = [];
        foreach ($data['media'] ?? [] as $medium) {
            $disc = (int) ($medium['position'] ?? 1);
            foreach ($medium['tracks'] ?? [] as $track) {
                $length = $track['length'] ?? $track['recording']['length'] ?? null;
                $tracks[] = new ReleaseTrack(
                    disc: $disc,
                    position: (int) ($track['position'] ?? \count($tracks) + 1),
                    title: (string) ($track['title'] ?? $track['recording']['title'] ?? ''),
                    artist: $this->artistCredit($track['artist-credit'] ?? $track['recording']['artist-credit'] ?? []) ?: $albumArtist,
                    duration: $length !== null ? (int) round($length / 1000) : null,
                    recordingId: $track['recording']['id'] ?? null,
                );
            }
        }

        $group = $data['release-group'] ?? [];
        $date = $group['first-release-date'] ?? $data['date'] ?? '';
        $genres = $data['genres'] ?? $group['genres'] ?? [];
        usort($genres, static fn(array $a, array $b): int => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        $secondary = $group['secondary-types'] ?? [];

        return new ReleaseCandidate(
            id: (string) $data['id'],
            // The release group title is the canonical album name, without
            // edition suffixes like "(20th Anniversary Edition)".
            title: (string) ($group['title'] ?? $data['title'] ?? ''),
            artist: $albumArtist,
            year: preg_match('/^(\d{4})/', (string) $date, $m) === 1 ? (int) $m[1] : null,
            genre: isset($genres[0]['name']) ? ucwords((string) $genres[0]['name']) : null,
            releaseGroupId: $group['id'] ?? null,
            tracks: $tracks,
            isCompilation: \in_array('Compilation', $secondary, true) || mb_strtolower($albumArtist) === 'various artists',
            status: $data['status'] ?? null,
            secondaryTypes: $secondary,
        );
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $query['fmt'] = 'json';
        $url = self::BASE_URL . $path . '?' . http_build_query($query);

        return $this->cache->get('mb_' . hash('xxh128', $url), function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetch($url);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->throttle();
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['User-Agent' => $this->musicbrainzUserAgent, 'Accept' => 'application/json'],
                    'timeout' => self::IDLE_TIMEOUT,
                    'max_duration' => self::MAX_DURATION,
                ]);
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                // Timeout, DNS, connection reset: transient more often than not.
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->clock->sleep(min(self::MAX_BACKOFF, 2 ** $attempt));
                    continue;
                }
                throw new EnrichmentException(\sprintf('MusicBrainz unreachable (%s) for %s', $e->getMessage(), $url), 0, $e);
            }

            if ($status === 404) {
                return [];
            }
            // 503 = rate limited / overloaded (the limit is per IP, shared with
            // any other MusicBrainz client on this machine): back off and retry.
            if (($status === 503 || $status === 429) && $attempt < self::MAX_ATTEMPTS) {
                $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
                $this->clock->sleep(min(self::MAX_BACKOFF, max($retryAfter, 2 ** $attempt)));
                continue;
            }
            if ($status !== 200) {
                throw new EnrichmentException(\sprintf('MusicBrainz returned HTTP %d for %s', $status, $url));
            }

            try {
                return $response->toArray();
            } catch (TransportExceptionInterface $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->clock->sleep(min(self::MAX_BACKOFF, 2 ** $attempt));
                    continue;
                }
                throw new EnrichmentException(\sprintf('MusicBrainz unreachable (%s) for %s', $e->getMessage(), $url), 0, $e);
            }
        }
    }

    private function throttle(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        $wait = $this->lastRequestAt + self::MIN_INTERVAL - $now;
        if ($wait > 0) {
            $this->clock->sleep($wait);
        }
        $this->lastRequestAt = (float) $this->clock->now()->format('U.u');
    }

    /**
     * @param array<string, mixed> $r
     */
    private function releaseSummary(array $r): ReleaseSummary
    {
        $group = $r['release-group'] ?? [];

        return new ReleaseSummary(
            id: (string) ($r['id'] ?? ''),
            title: (string) ($r['title'] ?? ''),
            artist: $this->artistCredit($r['artist-credit'] ?? []),
            date: $r['date'] ?? null,
            status: $r['status'] ?? null,
            primaryType: $group['primary-type'] ?? null,
            secondaryTypes: $group['secondary-types'] ?? [],
            trackCount: (int) ($r['track-count'] ?? 0),
            score: isset($r['score']) ? ((int) $r['score']) / 100 : 1.0,
            formats: array_values(array_filter(array_map(static fn(array $m): string => (string) ($m['format'] ?? ''), $r['media'] ?? []))),
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private function recording(array $r): RecordingMatch
    {
        return new RecordingMatch(
            id: (string) $r['id'],
            title: (string) ($r['title'] ?? ''),
            artist: $this->artistCredit($r['artist-credit'] ?? []),
            duration: isset($r['length']) ? (int) round($r['length'] / 1000) : null,
            score: isset($r['score']) ? ((int) $r['score']) / 100 : 1.0,
            releases: array_values(array_map(fn(array $rel): ReleaseSummary => $this->releaseSummary($rel), $r['releases'] ?? [])),
            disambiguation: (string) ($r['disambiguation'] ?? ''),
        );
    }

    /**
     * @param list<array<string, mixed>> $credits
     */
    private function artistCredit(array $credits): string
    {
        $name = '';
        foreach ($credits as $credit) {
            $name .= ($credit['name'] ?? $credit['artist']['name'] ?? '') . ($credit['joinphrase'] ?? '');
        }

        return trim($name);
    }

    /** Quotes a value for a Lucene phrase query. */
    /**
     * Forgiving variant of {@see searchRecordings()} for names the exact
     * phrase misses ("Last Ressort", "California Dreaming" for "Dreamin'"):
     * every word required, words of 4+ letters fuzzy. Without an artist, any
     * artist's recording of that title comes back — the caller filters.
     *
     * @return list<RecordingMatch>
     */
    public function searchRecordingsFuzzy(?string $artist, string $title, int $limit = 5, bool $excludeLive = false): array
    {
        $titleTerms = $this->fuzzyTerms($title);
        if ($titleTerms === '') {
            return [];
        }
        $artistTerms = $artist !== null ? $this->fuzzyTerms($artist) : '';
        $query = 'recording:(' . $titleTerms . ')' . ($artistTerms !== '' ? ' AND artist:(' . $artistTerms . ')' : '')
            . ($excludeLive ? ' AND NOT comment:live' : '');
        $data = $this->get('recording', ['query' => $query, 'limit' => $limit]);

        return array_values(array_map(fn(array $r): RecordingMatch => $this->recording($r), $data['recordings'] ?? []));
    }

    /** "Last Ressort" → "+last~ +ressort~" (letters and digits only: nothing for Lucene to choke on). */
    private function fuzzyTerms(string $value): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static fn(string $w): string => '+' . $w . (mb_strlen($w) >= 4 ? '~' : ''), $words));
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
