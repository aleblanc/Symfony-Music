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
    private function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
