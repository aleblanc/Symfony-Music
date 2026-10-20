<?php

namespace App\Service\Spotify;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Account-free sources of Spotify ids:
 *
 * - MusicBrainz: artist → Wikidata item + Spotify artist id; album → Spotify album ids
 *   (url relationships). Rate limit 1 request/second, honoured here.
 * - Wikidata: Spotify album ids (P2205) and track ids (P2207) of an artist's works.
 * - open.spotify.com/embed pages: the public, server-rendered player widget;
 *   its JSON lists an album's tracks (or an artist's top 10) with ids and
 *   durations. This is scraping a public page, not an API: it can break when
 *   Spotify changes the page, and requests are spaced out to stay polite.
 */
class PublicCatalog
{
    private const USER_AGENT = 'PHPMusic/1.0 (self-hosted music library; Spotify link matcher)';
    private const MB_URL = 'https://musicbrainz.org/ws/2';
    private const MB_INTERVAL_US = 1_100_000;

    private float $lastMusicBrainzCall = 0.0;
    private float $lastEmbedCall = 0.0;
    private int $embedIntervalUs = 500_000;

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function setEmbedDelay(int $milliseconds): void
    {
        $this->embedIntervalUs = max(0, $milliseconds) * 1000;
    }

    /**
     * @return array{mbid: string, name: string, wikidata: ?string, spotify: ?string}|null
     */
    public function findArtist(string $name): ?array
    {
        // Plain query (name + aliases + sort name): a quoted artist:"…" phrase
        // misses names written with typographic characters ("blink‐182").
        $search = $this->musicBrainz('/artist', ['query' => $this->luceneEscape($name), 'limit' => 5]);
        $wanted = $this->key($name);
        $artist = null;
        foreach (\is_array($search['artists'] ?? null) ? $search['artists'] : [] as $candidate) {
            if (!\is_array($candidate) || (int) ($candidate['score'] ?? 0) < 90) {
                continue;
            }
            $names = [(string) ($candidate['name'] ?? '')];
            foreach (\is_array($candidate['aliases'] ?? null) ? $candidate['aliases'] : [] as $alias) {
                $names[] = \is_array($alias) ? (string) ($alias['name'] ?? '') : '';
            }
            if (\in_array($wanted, array_map($this->key(...), $names), true)) {
                $artist = $candidate;
                break;
            }
        }
        if ($artist === null || !\is_string($artist['id'] ?? null)) {
            return null;
        }

        $details = $this->musicBrainz('/artist/' . $artist['id'], ['inc' => 'url-rels']);
        $wikidata = $spotify = null;
        foreach ($this->urlRelations($details) as $url) {
            if ($wikidata === null && preg_match('~wikidata\.org/wiki/(Q\d+)~', $url, $m)) {
                $wikidata = $m[1];
            }
            if ($spotify === null && preg_match('~open\.spotify\.com/artist/([A-Za-z0-9]{22})~', $url, $m)) {
                $spotify = $m[1];
            }
        }

        return ['mbid' => $artist['id'], 'name' => (string) $artist['name'], 'wikidata' => $wikidata, 'spotify' => $spotify];
    }

    /**
     * Spotify album ids of the MusicBrainz release group best matching $album.
     *
     * @return list<string>
     */
    public function musicBrainzAlbumIds(string $artistMbid, string $album): array
    {
        $search = $this->musicBrainz('/release-group', [
            'query' => \sprintf('releasegroup:(%s) AND arid:%s', $this->luceneEscape($album), $artistMbid),
            'limit' => 3,
        ]);
        $group = null;
        foreach (\is_array($search['release-groups'] ?? null) ? $search['release-groups'] : [] as $candidate) {
            if (\is_array($candidate) && (int) ($candidate['score'] ?? 0) >= 90 && $this->key((string) ($candidate['title'] ?? '')) === $this->key($album)) {
                $group = (string) $candidate['id'];
                break;
            }
        }
        if ($group === null) {
            return [];
        }

        $releases = $this->musicBrainz('/release', ['release-group' => $group, 'inc' => 'url-rels', 'limit' => 50]);
        $ids = [];
        foreach (\is_array($releases['releases'] ?? null) ? $releases['releases'] : [] as $release) {
            foreach ($this->urlRelations(\is_array($release) ? $release : []) as $url) {
                if (preg_match('~open\.spotify\.com/album/([A-Za-z0-9]{22})~', $url, $m)) {
                    $ids[$m[1]] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Spotify ids Wikidata knows for an artist's albums and tracks.
     *
     * @return array{albums: list<array{title: string, id: string}>, tracks: list<SpotifyTrack>}
     */
    public function wikidataWorks(string $qid, string $artistName): array
    {
        $sparql = \sprintf(
            'SELECT ?sid ?label ?kind WHERE { VALUES (?p ?kind) { (wdt:P2205 "album") (wdt:P2207 "track") } '
            . '?item wdt:P175 wd:%s ; ?p ?sid . OPTIONAL { ?item rdfs:label ?label . FILTER(LANG(?label) = "en") } }',
            preg_replace('/[^Q0-9]/', '', $qid),
        );
        $unpaced = 0.0;
        $data = $this->requestJson('Wikidata', 'https://query.wikidata.org/sparql', [
            'query' => ['query' => $sparql],
            'headers' => ['Accept' => 'application/sparql-results+json', 'User-Agent' => self::USER_AGENT],
            'timeout' => 30,
        ], $unpaced);

        $albums = $tracks = [];
        foreach (\is_array($data['results']['bindings'] ?? null) ? $data['results']['bindings'] : [] as $row) {
            $id = $row['sid']['value'] ?? null;
            $label = $row['label']['value'] ?? null;
            if (!\is_string($id) || !preg_match('/^[A-Za-z0-9]{22}$/', $id) || !\is_string($label)) {
                continue;
            }
            if (($row['kind']['value'] ?? '') === 'album') {
                $albums[] = ['title' => $label, 'id' => $id];
            } else {
                $tracks[] = new SpotifyTrack($id, $label, [$artistName], null, null);
            }
        }

        return ['albums' => $albums, 'tracks' => $tracks];
    }

    /**
     * Tracks listed by the public embed widget of an album (all tracks) or an
     * artist (top 10).
     *
     * @param 'album'|'artist' $type
     *
     * @return list<SpotifyTrack>
     *
     * @throws SpotifyException when Spotify keeps refusing the requests
     */
    public function embedTracks(string $type, string $id): array
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->wait($this->lastEmbedCall, $this->embedIntervalUs);
            $response = $this->httpClient->request('GET', \sprintf('https://open.spotify.com/embed/%s/%s', $type, $id), [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($status === 429) {
                sleep(max(2, min((int) ($response->getHeaders(false)['retry-after'][0] ?? 5), 120)));
                continue;
            }
            if ($status !== 200) {
                return []; // removed album, region lock…
            }

            return $this->parseEmbed($response->getContent(false));
        }

        throw new SpotifyException('open.spotify.com keeps rate-limiting the embed pages (HTTP 429): retry later with a larger --delay.');
    }

    /**
     * @return list<SpotifyTrack>
     */
    public function parseEmbed(string $html): array
    {
        if (!preg_match('~<script id="__NEXT_DATA__" type="application/json">(.*?)</script>~s', $html, $m)) {
            return [];
        }
        $data = json_decode($m[1], true);
        $entity = \is_array($data) ? ($data['props']['pageProps']['state']['data']['entity'] ?? null) : null;
        if (!\is_array($entity) || !\is_array($entity['trackList'] ?? null)) {
            return [];
        }

        $tracks = [];
        foreach ($entity['trackList'] as $item) {
            if (!\is_array($item) || !preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', (string) ($item['uri'] ?? ''), $id)) {
                continue;
            }
            $artists = array_values(array_filter(array_map('trim', explode(',', (string) ($item['subtitle'] ?? '')))));
            $tracks[] = new SpotifyTrack(
                $id[1],
                (string) ($item['title'] ?? ''),
                $artists,
                $entity['type'] === 'album' ? (string) ($entity['name'] ?? '') : null,
                is_numeric($item['duration'] ?? null) ? (int) round($item['duration'] / 1000) : null,
            );
        }

        return $tracks;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     *
     * @throws SpotifyException when MusicBrainz stays unavailable
     */
    private function musicBrainz(string $path, array $query): array
    {
        return $this->requestJson('MusicBrainz', self::MB_URL . $path, [
            'query' => $query + ['fmt' => 'json'],
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
            'timeout' => 20,
        ], $this->lastMusicBrainzCall, self::MB_INTERVAL_US);
    }

    /**
     * GET returning decoded JSON; [] for a definitive answer without data
     * (404, bad JSON). Overload/rate-limit responses (429, 5xx) and transport
     * errors are retried with backoff (~2 min in total), then reported: a transient failure
     * must not be mistaken for "this artist has no Spotify id".
     *
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     *
     * @throws SpotifyException
     */
    private function requestJson(string $service, string $url, array $options, float &$lastCall, int $intervalUs = 0): array
    {
        $error = '';
        foreach ([0, 5, 15, 30, 60] as $backoff) { // MusicBrainz throttles an IP for a while after bursts
            sleep($backoff);
            $this->wait($lastCall, $intervalUs);
            try {
                $response = $this->httpClient->request('GET', $url, $options);
                $status = $response->getStatusCode();
                if ($status === 429 || $status >= 500) {
                    $error = 'HTTP ' . $status;
                    continue;
                }

                return $status === 200 ? $response->toArray(false) : [];
            } catch (\JsonException) {
                return [];
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        throw new SpotifyException(\sprintf('%s unavailable (%s), try again later.', $service, $error));
    }

    private function luceneEscape(string $s): string
    {
        return addcslashes($s, '+-&|!(){}[]^"~*?:\\/');
    }

    /**
     * @param array<mixed> $entity
     *
     * @return list<string>
     */
    private function urlRelations(array $entity): array
    {
        $urls = [];
        foreach (\is_array($entity['relations'] ?? null) ? $entity['relations'] : [] as $relation) {
            $url = \is_array($relation) ? ($relation['url']['resource'] ?? null) : null;
            if (\is_string($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function wait(float &$last, int $intervalUs): void
    {
        $elapsedUs = (int) ((microtime(true) - $last) * 1_000_000);
        if ($elapsedUs < $intervalUs) {
            usleep($intervalUs - $elapsedUs);
        }
        $last = microtime(true);
    }

    private function key(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists(\Transliterator::class)) {
            $s = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($s) ?: $s;
        }

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
    }
}
