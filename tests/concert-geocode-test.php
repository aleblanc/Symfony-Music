<?php

/*
 * Standalone test for the concerts map geocoding against canned Nominatim
 * responses: structured city search, free-text fallback, country name when
 * the code is unknown, not found, refusal and outage → exceptions; and the
 * CityGeo cache key.
 *
 * Run:  php tests/concert-geocode-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Entity\CityGeo;
use App\Service\Artist\NominatimClient;
use App\Service\Artist\NominatimRefusedException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}\n";
    } else {
        ++$failures;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * @param list<MockResponse> $responses
 * @param list<array{url: string, options: array<mixed>}> $requests
 */
function client(array &$responses, array &$requests): NominatimClient
{
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
        $requests[] = ['url' => $url, 'options' => $options];

        return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
    });

    return new NominatimClient($http, 'SymfonyMusicTest/1.0 (test@example.com)');
}

/** @return array<string, string> */
function query(string $url): array
{
    parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

    return $query;
}

$hit = static fn(string $lat, string $lon): MockResponse => new MockResponse(json_encode([['lat' => $lat, 'lon' => $lon, 'name' => 'x']]));
$none = static fn(): MockResponse => new MockResponse('[]');

echo "Structured city search\n";
$requests = [];
$responses = [$hit('48.8588897', '2.320041')];
$found = client($responses, $requests)->locate('Paris', 'FR', 'France');
check('coordinates as floats', $found === [48.8588897, 2.320041], json_encode($found));
check('one request', \count($requests) === 1);
$q = query($requests[0]['url']);
check('city + country code', ($q['city'] ?? null) === 'Paris' && ($q['countrycodes'] ?? null) === 'fr' && !isset($q['country']), json_encode($q));
check('jsonv2, first result only', ($q['format'] ?? null) === 'jsonv2' && ($q['limit'] ?? null) === '1');
$ua = implode("\n", $requests[0]['options']['headers'] ?? []);
check('identifying User-Agent', str_contains($ua, 'SymfonyMusicTest/1.0'), $ua);

echo "Free-text fallback\n";
$requests = [];
$responses = [$none(), $hit('40.6526006', '-73.9497211')];
$found = client($responses, $requests)->locate('Brooklyn', 'US', 'US');
check('found by the second search', $found === [40.6526006, -73.9497211], json_encode($found));
$q = query($requests[1]['url'] ?? '');
check('q + settlement + country code', ($q['q'] ?? null) === 'Brooklyn' && ($q['featureType'] ?? null) === 'settlement' && ($q['countrycodes'] ?? null) === 'us' && !isset($q['city']), json_encode($q));

echo "Unknown country code\n";
$requests = [];
$responses = [$hit('51.5', '-0.12')];
client($responses, $requests)->locate('London', null, 'Middle Earth');
$q = query($requests[0]['url']);
check('country name instead', ($q['country'] ?? null) === 'Middle Earth' && !isset($q['countrycodes']), json_encode($q));
$requests = [];
$responses = [$none(), $hit('1', '1')];
$found = client($responses, $requests)->locate('Nowhere', null, 'Middle Earth');
check('no free-text fallback with a country name (structured only)', $found === null && \count($requests) === 1);

echo "Not found\n";
$requests = [];
$responses = [$none(), $none()];
check('null', client($responses, $requests)->locate('Xyzzyville', 'FR', 'France') === null);
check('both searches tried', \count($requests) === 2);
$requests = [];
$responses = [new MockResponse(json_encode([['lat' => 'n/a', 'lon' => '2']])), $none()];
check('no usable coordinates → null', client($responses, $requests)->locate('Paris', null, null) === null && \count($requests) === 2);

echo "Refused / outage\n";
foreach ([403, 429] as $code) {
    $requests = [];
    $responses = [new MockResponse('', ['http_code' => $code])];
    try {
        client($responses, $requests)->locate('Paris', 'FR', 'France');
        check("HTTP {$code} → NominatimRefusedException", false, 'no exception');
    } catch (NominatimRefusedException) {
        check("HTTP {$code} → NominatimRefusedException", true);
    }
}
$requests = [];
$responses = [new MockResponse('', ['http_code' => 503])];
try {
    client($responses, $requests)->locate('Paris', 'FR', 'France');
    check('HTTP 503 → RuntimeException', false, 'no exception');
} catch (NominatimRefusedException) {
    check('HTTP 503 → RuntimeException', false, 'refused instead');
} catch (\RuntimeException) {
    check('HTTP 503 → RuntimeException', true);
}
$requests = [];
$responses = [new MockResponse('', ['error' => 'Connection refused'])];
try {
    client($responses, $requests)->locate('Paris', 'FR', 'France');
    check('network error → RuntimeException', false, 'no exception');
} catch (\RuntimeException) {
    check('network error → RuntimeException', true);
}

echo "CityGeo::place\n";
check('city|code, lowercased', CityGeo::place(['city' => ' Paris ', 'country' => 'France', 'countryCode' => 'FR']) === 'paris|fr');
check('country name without code', CityGeo::place(['city' => 'Gotham', 'country' => 'Narnia', 'countryCode' => null]) === 'gotham|narnia');
check('no city → null', CityGeo::place(['city' => null, 'country' => 'France', 'countryCode' => 'FR']) === null);
check('unicode lowercased', CityGeo::place(['city' => 'ÉVRY', 'country' => 'France', 'countryCode' => 'FR']) === 'évry|fr');

echo $failures === 0 ? "\nAll good.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
