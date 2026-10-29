<?php

namespace App\Service\Artist;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coordinates of a concert city without an API key: OpenStreetMap
 * Nominatim. Its usage policy asks for an identifying User-Agent and at
 * most one request per second (the caller waits), and results kept rather
 * than asked again (CityGeo). The structured city search first, then the
 * name as free text, for the boroughs and towns Songkick names that are not
 * tagged as a city ("Brooklyn"). When Nominatim refuses the request (403,
 * 429), NominatimRefusedException: the caller stops for this run.
 */
final class NominatimClient
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * @return ?array{float, float} [lat, lng], null when Nominatim knows no such place
     *
     * @throws NominatimRefusedException when Nominatim refuses the request
     * @throws \RuntimeException when Nominatim cannot be read
     */
    public function locate(string $city, ?string $countryCode, ?string $country, int $pauseMicroseconds = 0): ?array
    {
        $where = $countryCode !== null ? ['countrycodes' => strtolower($countryCode)] : ($country !== null ? ['country' => $country] : []);
        $found = $this->search(['city' => $city] + $where);
        if ($found === null && !isset($where['country'])) {
            usleep($pauseMicroseconds);
            $found = $this->search(['q' => $city, 'featureType' => 'settlement'] + $where);
        }

        return $found;
    }

    /**
     * @param array<string, string> $query
     *
     * @return ?array{float, float}
     */
    private function search(array $query): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::URL, [
                'query' => $query + ['format' => 'jsonv2', 'limit' => '1'],
                'headers' => ['User-Agent' => $this->musicbrainzUserAgent, 'Accept' => 'application/json'],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if (\in_array($status, [403, 429], true)) {
                throw new NominatimRefusedException(\sprintf('Nominatim refused the request (HTTP %d)', $status));
            }
            if ($status !== 200) {
                throw new \RuntimeException(\sprintf('HTTP %d', $status));
            }
            $results = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $first = $results[0] ?? null;
        if (!\is_array($first) || !is_numeric($first['lat'] ?? null) || !is_numeric($first['lon'] ?? null)) {
            return null;
        }

        return [(float) $first['lat'], (float) $first['lon']];
    }
}
