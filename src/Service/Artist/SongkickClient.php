<?php

namespace App\Service\Artist;

use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Upcoming concerts without an API key: the artist's public Songkick
 * calendar page, which holds every date as schema.org MusicEvent JSON-LD
 * (venue, city, country, status). The Songkick id comes from the artist's
 * MusicBrainz links. When Songkick refuses the request (403, 406, 429),
 * SongkickRefusedException: the caller stops for this run, nothing tries to
 * get around it.
 */
final class SongkickClient
{
    private const USER_AGENT = 'PHPMusic/1.0 (personal music library)';
    /** Songkick names that are not the English country names Intl knows. */
    private const COUNTRY_ALIASES = ['UK' => 'GB', 'US' => 'US', 'USA' => 'US', 'Czech Republic' => 'CZ', 'Russia' => 'RU', 'South Korea' => 'KR', 'Vietnam' => 'VN', 'Turkey' => 'TR'];
    /** Calendar pages read per artist at most (50 dates each). */
    private const MAX_PAGES = 5;
    private const STATUSES = ['EventCancelled' => 'cancelled', 'EventPostponed' => 'postponed', 'EventRescheduled' => 'rescheduled', 'EventMovedOnline' => 'online'];

    /** @var ?array<string, string> English country name => ISO code */
    private ?array $countryCodes = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * The artist's Songkick id from its MusicBrainz links: '' when it has none, null when MusicBrainz cannot be read.
     */
    public function songkickId(string $mbid): ?string
    {
        $relations = null;
        // MusicBrainz often answers 503 ("server busy") for a moment: one more try.
        for ($attempt = 1; $attempt <= 3 && $relations === null; ++$attempt) {
            if ($attempt > 1) {
                sleep(2 * ($attempt - 1));
            }
            try {
                $response = $this->httpClient->request('GET', 'https://musicbrainz.org/ws/2/artist/' . rawurlencode($mbid) . '?inc=url-rels&fmt=json', [
                    'headers' => ['User-Agent' => $this->musicbrainzUserAgent, 'Accept' => 'application/json'],
                    'timeout' => 20,
                ]);
                if ($response->getStatusCode() === 200) {
                    $relations = $response->toArray()['relations'] ?? [];
                }
            } catch (ExceptionInterface) {
            }
        }
        if ($relations === null) {
            return null;
        }
        foreach ($relations as $relation) {
            if (preg_match('#songkick\.com/artists/(\d+)#', (string) ($relation['url']['resource'] ?? ''), $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    /**
     * Every page of the calendar (50 dates each), following its "next" link,
     * $pause seconds apart.
     *
     * @return list<array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string}> soonest first
     *
     * @throws SongkickRefusedException when Songkick refuses the request
     * @throws \RuntimeException when a page cannot be read
     */
    public function upcomingConcerts(string $songkickId, int $pause = 0): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        // An old id (renamed artist) redirects to the current page.
        $url = 'https://www.songkick.com/artists/' . rawurlencode($songkickId) . '/calendar';
        $concerts = [];
        for ($page = 1; $page <= self::MAX_PAGES && $url !== null; ++$page) {
            if ($page > 1 && $pause > 0) {
                sleep($pause);
            }
            $html = $this->page($url);
            if ($html === null) {
                break;
            }
            foreach ($this->parse($html, $today) as $concert) {
                $concerts[$concert['id']] = $concert;
            }
            $url = preg_match('#<a[^>]*class="next_page"[^>]*href="([^"]+)"#', $html, $m) === 1
                ? 'https://www.songkick.com' . html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5)
                : null;
        }
        $concerts = array_values($concerts);
        usort($concerts, static fn (array $a, array $b): int => strcmp($a['date'] . ($a['time'] ?? ''), $b['date'] . ($b['time'] ?? '')));

        return $concerts;
    }

    /**
     * One calendar page, null when it does not exist (404).
     *
     * @throws SongkickRefusedException when Songkick refuses the request
     * @throws \RuntimeException when the page cannot be read
     */
    private function page(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 20,
                'max_duration' => 40,
            ]);
            $status = $response->getStatusCode();
            if (\in_array($status, [403, 406, 429], true)) {
                throw new SongkickRefusedException(\sprintf('Songkick refused the request (HTTP %d)', $status));
            }
            if ($status === 404) {
                return null;
            }
            if ($status !== 200) {
                throw new \RuntimeException(\sprintf('HTTP %d', $status));
            }

            return $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string}>
     */
    public function parse(string $html, string $today): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
        $concerts = [];
        foreach ($blocks[1] as $json) {
            $data = json_decode($json, true);
            if (!\is_array($data)) {
                continue;
            }
            foreach (array_is_list($data) ? $data : [$data] as $event) {
                if (!\is_array($event) || ($event['@type'] ?? null) !== 'MusicEvent' || !\is_string($event['startDate'] ?? null)) {
                    continue;
                }
                $concert = $this->concert($event);
                if ($concert !== null && $concert['date'] >= $today) {
                    $concerts[$concert['id']] = $concert;
                }
            }
        }
        $concerts = array_values($concerts);
        usort($concerts, static fn (array $a, array $b): int => strcmp($a['date'] . ($a['time'] ?? ''), $b['date'] . ($b['time'] ?? '')));

        return $concerts;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return ?array{id: string, date: string, time: ?string, title: string, venue: ?string, city: ?string, country: ?string, countryCode: ?string, festival: bool, status: string, url: string}
     */
    private function concert(array $event): ?array
    {
        $url = (string) preg_replace('/\?.*$/', '', (string) ($event['url'] ?? ''));
        // .../concerts/43274292-... or .../festivals/774039-wacken/id/43100472-wacken-festival-2027
        if (preg_match('#songkick\.com/(?:concerts/(\d+)|festivals/\d+-([a-z0-9-]+)/id/(\d+))#', $url, $m) !== 1) {
            return null;
        }
        $festival = ($m[3] ?? '') !== '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}))?/', (string) $event['startDate'], $d) !== 1) {
            return null;
        }
        $location = \is_array($event['location'] ?? null) ? $event['location'] : [];
        $address = \is_array($location['address'] ?? null) ? $location['address'] : [];
        $venue = self::text($location['name'] ?? null);
        $country = self::text($address['addressCountry'] ?? null);
        $statusKey = preg_replace('#^.*/#', '', (string) ($event['eventStatus'] ?? ''));

        return [
            'id' => $festival ? $m[3] : $m[1],
            'date' => $d[1],
            'time' => isset($d[2]) && $d[2] !== '00:00' ? $d[2] : null,
            // A festival under its own name ("wacken" → "Wacken"), a concert under its venue.
            'title' => $festival ? ucwords(str_replace('-', ' ', $m[2])) : ($venue ?? self::text($event['name'] ?? null) ?? '?'),
            'venue' => $venue,
            'city' => self::text($address['addressLocality'] ?? null),
            'country' => $country,
            'countryCode' => $country !== null ? $this->countryCode($country) : null,
            'festival' => $festival,
            'status' => self::STATUSES[$statusKey] ?? 'scheduled',
            'url' => $url,
        ];
    }

    private function countryCode(string $name): ?string
    {
        if (isset(self::COUNTRY_ALIASES[$name])) {
            return self::COUNTRY_ALIASES[$name];
        }
        $this->countryCodes ??= array_flip(array_map('mb_strtolower', Countries::getNames('en')));

        return $this->countryCodes[mb_strtolower($name)] ?? null;
    }

    private static function text(mixed $value): ?string
    {
        $value = \is_string($value) ? trim(html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5)) : '';

        return $value !== '' ? $value : null;
    }
}
