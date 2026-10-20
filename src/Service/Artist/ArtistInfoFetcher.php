<?php

namespace App\Service\Artist;

use App\Service\Enrichment\MusicBrainzClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds what is known about an artist, free and without API keys:
 * MusicBrainz (the artist, its links) → Wikidata (photo, YouTube channel,
 * Wikipedia article) → Wikipedia (summary). Homonyms ("Muse"...) are told
 * apart by the albums the library holds.
 */
class ArtistInfoFetcher
{
    /** Wikipedia languages, by preference. */
    public const LANGUAGES = ['fr', 'en'];

    /** Link label by host (suffix match), in display order. */
    private const LINK_HOSTS = [
        'open.spotify.com' => 'Spotify',
        'deezer.com' => 'Deezer',
        'music.apple.com' => 'Apple Music',
        'bandcamp.com' => 'Bandcamp',
        'soundcloud.com' => 'SoundCloud',
        'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'twitter.com' => 'X (Twitter)',
        'x.com' => 'X (Twitter)',
        'tiktok.com' => 'TikTok',
        'last.fm' => 'Last.fm',
        'discogs.com' => 'Discogs',
    ];

    public function __construct(
        private readonly MusicBrainzClient $musicBrainz,
        private readonly YouTubeClient $youtube,
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * @param list<string> $albums album titles of the artist in the library
     *
     * @return ?array{mbid: string, name: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, summary: ?string, youtubeChannelId: ?string, youtubeUrl: ?string, links: list<array{type: string, label: string, url: string}>}
     */
    public function fetch(string $name, array $albums = []): ?array
    {
        $artist = $this->pickArtist($name, $albums);
        if ($artist === null) {
            return null;
        }

        $wikidataId = null;
        $wikipediaUrl = null;
        $youtubeUrl = null;
        $official = null;
        $links = [];
        foreach ($this->musicBrainz->getArtistUrls($artist['id']) as $rel) {
            $url = $rel['url'];
            $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
            if ($host === 'www.wikidata.org' && preg_match('~/(Q\d+)~', $url, $m) === 1) {
                $wikidataId ??= $m[1];
            } elseif (str_ends_with($host, 'wikipedia.org')) {
                $wikipediaUrl ??= $url;
            } elseif (($host === 'www.youtube.com' || $host === 'youtube.com') && !str_contains($url, '/watch') && !str_contains($url, '/playlist')) {
                $youtubeUrl ??= $url;
            } elseif ($rel['type'] === 'official homepage') {
                $official ??= $url;
            } else {
                foreach (self::LINK_HOSTS as $suffix => $label) {
                    if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                        $links[$label] ??= ['type' => strtolower(strtok($label, ' ') ?: $label), 'label' => $label, 'url' => $url];
                    }
                }
            }
        }

        $wikidata = $wikidataId !== null ? $this->wikidata($wikidataId) : null;
        $description = $wikidata['description'] ?? $artist['disambiguation'];
        $imageUrl = $wikidata['imageUrl'] ?? null;
        $official ??= $wikidata['official'] ?? null;
        $youtubeChannelId = $wikidata['youtubeChannelId'] ?? null;
        if ($youtubeChannelId === null && $youtubeUrl !== null) {
            $youtubeChannelId = $this->youtube->resolveChannelId($youtubeUrl);
        }

        $summary = null;
        $article = $wikidata['article'] ?? null;
        if ($article === null && $wikipediaUrl !== null && preg_match('~^https?://([a-z-]+)\.wikipedia\.org/wiki/(.+)$~', $wikipediaUrl, $m) === 1) {
            $article = ['lang' => $m[1], 'title' => rawurldecode($m[2])];
        }
        if ($article !== null) {
            $page = $this->wikipediaSummary($article['lang'], $article['title']);
            $wikipediaUrl = $page['url'] ?? 'https://' . $article['lang'] . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $article['title']));
            $summary = $page['extract'] ?? null;
            $imageUrl ??= $page['image'] ?? null;
        }

        $ordered = [];
        if ($official !== null) {
            $ordered[] = ['type' => 'official', 'label' => 'Official site', 'url' => $official];
        }
        foreach (self::LINK_HOSTS as $label) {
            if (isset($links[$label])) {
                $ordered[] = $links[$label];
                unset($links[$label]);
            }
        }
        $ordered[] = ['type' => 'musicbrainz', 'label' => 'MusicBrainz', 'url' => 'https://musicbrainz.org/artist/' . $artist['id']];

        return [
            'mbid' => $artist['id'],
            'name' => $artist['name'],
            'wikidataId' => $wikidataId,
            'description' => $description,
            'imageUrl' => $imageUrl,
            'wikipediaUrl' => $wikipediaUrl,
            'summary' => $summary,
            'youtubeChannelId' => $youtubeChannelId,
            'youtubeUrl' => $youtubeUrl ?? ($youtubeChannelId !== null ? 'https://www.youtube.com/channel/' . $youtubeChannelId : null),
            'links' => $ordered,
        ];
    }

    /**
     * The MusicBrainz artist with this exact name (or alias); among
     * homonyms, the one sharing the most album titles with the library.
     *
     * @param list<string> $albums
     *
     * @return ?array{id: string, name: string, score: int, disambiguation: ?string, aliases: list<string>}
     */
    public function pickArtist(string $name, array $albums): ?array
    {
        $wanted = self::normalize($name);
        if ($wanted === '') {
            return null;
        }
        $exact = array_values(array_filter(
            $this->musicBrainz->searchArtists($name, 8),
            static fn(array $a) => $a['score'] >= 80 && (self::normalize($a['name']) === $wanted
                || \in_array($wanted, array_map(self::normalize(...), $a['aliases']), true)),
        ));
        if (\count($exact) <= 1) {
            return $exact[0] ?? null;
        }

        $owned = array_flip(array_filter(array_map(self::normalize(...), $albums)));
        $best = $exact[0];
        $bestOverlap = 0;
        foreach (\array_slice($exact, 0, 3) as $candidate) {
            $titles = array_unique(array_map(self::normalize(...), $this->musicBrainz->getArtistReleaseGroupTitles($candidate['id'])));
            $overlap = \count(array_filter($titles, static fn(string $t) => isset($owned[$t])));
            if ($overlap > $bestOverlap) {
                $best = $candidate;
                $bestOverlap = $overlap;
            }
        }

        return $best;
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $ascii = class_exists(\Transliterator::class) ? \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($s) : null;
        $s = \is_string($ascii) && $ascii !== '' ? $ascii : $s;
        $s = preg_replace('/^the\s+/', '', $s) ?? $s;
        $s = str_replace('&', 'and', $s);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;
    }

    /**
     * @return ?array{description: ?string, imageUrl: ?string, youtubeChannelId: ?string, official: ?string, article: ?array{lang: string, title: string}}
     */
    private function wikidata(string $id): ?array
    {
        $data = $this->getJson('https://www.wikidata.org/wiki/Special:EntityData/' . rawurlencode($id) . '.json');
        $entity = $data['entities'][$id] ?? null;
        if (!\is_array($entity)) {
            // Redirected item: the payload is keyed by the new id.
            $entity = \is_array($data['entities'] ?? null) ? (array_values($data['entities'])[0] ?? null) : null;
        }
        if (!\is_array($entity)) {
            return null;
        }

        $claim = static function (string $property) use ($entity): ?string {
            $value = $entity['claims'][$property][0]['mainsnak']['datavalue']['value'] ?? null;

            return \is_string($value) && $value !== '' ? $value : null;
        };

        $description = null;
        $article = null;
        foreach (self::LANGUAGES as $lang) {
            $description ??= $entity['descriptions'][$lang]['value'] ?? null;
            $title = $entity['sitelinks'][$lang . 'wiki']['title'] ?? null;
            if ($article === null && \is_string($title)) {
                $article = ['lang' => $lang, 'title' => $title];
            }
        }
        $image = $claim('P18');

        return [
            'description' => \is_string($description) ? $description : null,
            'imageUrl' => $image !== null ? 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode(str_replace(' ', '_', $image)) . '?width=600' : null,
            'youtubeChannelId' => ($channel = $claim('P2397')) !== null && preg_match('/^UC[\w-]{22}$/', $channel) === 1 ? $channel : null,
            'official' => $claim('P856'),
            'article' => $article,
        ];
    }

    /**
     * @return ?array{url: ?string, extract: ?string, image: ?string}
     */
    private function wikipediaSummary(string $lang, string $title): ?array
    {
        if (preg_match('/^[a-z-]{2,12}$/', $lang) !== 1) {
            return null;
        }
        $data = $this->getJson('https://' . $lang . '.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title)));
        if ($data === null) {
            return null;
        }
        $extract = trim((string) ($data['extract'] ?? ''));

        return [
            'url' => \is_string($data['content_urls']['desktop']['page'] ?? null) ? $data['content_urls']['desktop']['page'] : null,
            'extract' => $extract !== '' ? $extract : null,
            'image' => \is_string($data['originalimage']['source'] ?? null) ? $data['originalimage']['source'] : (\is_string($data['thumbnail']['source'] ?? null) ? $data['thumbnail']['source'] : null),
        ];
    }

    /**
     * @return ?array<string, mixed> null on any HTTP / network error (the profile is simply less complete)
     */
    private function getJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $this->musicbrainzUserAgent, 'Accept' => 'application/json'],
                'timeout' => 20,
                'max_duration' => 40,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->toArray();
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
