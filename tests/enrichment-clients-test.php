<?php

/*
 * Standalone test for the enrichment HTTP clients (MusicBrainz, AcoustID,
 * Cover Art Archive) against canned responses: parsing, User-Agent, 1 req/s
 * throttle, 503 retry, caching.
 *
 * Run:  php tests/enrichment-clients-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\AcoustIdClient;
use App\Service\Enrichment\CoverArtClient;
use App\Service\Enrichment\Fingerprint;
use App\Service\Enrichment\MusicBrainzClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
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

$release = [
    'id' => 'rel-1',
    'title' => 'OK Computer (Collector\'s Edition)',
    'date' => '2009-03-24',
    'artist-credit' => [['name' => 'Radiohead', 'joinphrase' => '']],
    'status' => 'Official',
    'release-group' => ['id' => 'rg-1', 'title' => 'OK Computer', 'primary-type' => 'Album', 'secondary-types' => [], 'first-release-date' => '1997-05-21'],
    'genres' => [['name' => 'art rock', 'count' => 3], ['name' => 'alternative rock', 'count' => 9]],
    'media' => [
        ['position' => 1, 'tracks' => [
            ['position' => 1, 'title' => 'Airbag', 'length' => 284200, 'recording' => ['id' => 'rec-1', 'title' => 'Airbag']],
            ['position' => 2, 'title' => 'Paranoid Android', 'length' => 383400, 'recording' => ['id' => 'rec-2', 'title' => 'Paranoid Android'],
                'artist-credit' => [['name' => 'Radiohead', 'joinphrase' => ' feat. '], ['name' => 'Nobody', 'joinphrase' => '']]],
        ]],
        ['position' => 2, 'tracks' => [
            ['position' => 1, 'title' => 'Lull', 'length' => null, 'recording' => ['id' => 'rec-3', 'title' => 'Lull', 'length' => 147000]],
        ]],
    ],
];

$requests = [];
$responses = [
    new MockResponse('', ['http_code' => 503]),
    new MockResponse(json_encode($release), ['http_code' => 200]),
    new MockResponse(json_encode(['releases' => [
        ['id' => 'rel-1', 'score' => 100, 'title' => 'OK Computer', 'track-count' => 12, 'status' => 'Official',
            'artist-credit' => [['name' => 'Radiohead']], 'release-group' => ['primary-type' => 'Album', 'secondary-types' => []]],
    ]]), ['http_code' => 200]),
];
$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
    $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

    return array_shift($responses);
});
$clock = new MockClock('2026-10-19 12:00:00');
$t0 = (float) $clock->now()->format('U.u');
$mb = new MusicBrainzClient($http, new ArrayAdapter(), $clock, 'SymfonyMusic/1.0 ( test )');

$r = $mb->getRelease('rel-1');
check('release parsed after a 503 retry; album title from the release group (no edition suffix)', $r !== null && $r->title === 'OK Computer', (string) $r?->title);
check('status + secondary types parsed', $r?->status === 'Official' && $r->secondaryTypes === [] && !$r->isUnwanted(false));
check('two HTTP calls (503 then 200)', \count($requests) === 2, (string) \count($requests));
check('User-Agent sent', in_array('User-Agent: SymfonyMusic/1.0 ( test )', $requests[0]['options']['headers'] ?? [], true), json_encode($requests[0]['options']['headers'] ?? []));
check('json format + includes requested', str_contains($requests[0]['url'], 'fmt=json') && str_contains(urldecode($requests[0]['url']), 'inc=recordings+artist-credits'));
check('album artist', $r?->artist === 'Radiohead');
check('original year from release group', $r?->year === 1997);
check('top genre by votes', $r?->genre === 'Alternative Rock', (string) $r?->genre);
check('three tracks over two discs', $r?->trackCount() === 3 && $r->discCount() === 2);
check('ms → s durations', $r?->tracks[0]->duration === 284 && $r->tracks[1]->duration === 383);
check('track length falls back to recording length', $r?->tracks[2]->duration === 147);
check('disc number', $r?->tracks[2]->disc === 2 && $r->tracks[2]->position === 1);
check('track artist credit with join phrase', $r?->tracks[1]->artist === 'Radiohead feat. Nobody', (string) $r?->tracks[1]->artist);
check('track artist falls back to album artist', $r?->tracks[0]->artist === 'Radiohead');
check('recording ids', $r?->tracks[0]->recordingId === 'rec-1');
check('not a compilation', $r?->isCompilation === false);

$again = $mb->getRelease('rel-1');
check('second lookup served from cache', \count($requests) === 2 && $again?->title === 'OK Computer');

$found = $mb->searchReleases('Radiohead', 'OK "Computer"');
check('search parsed', \count($found) === 1 && $found[0]->trackCount === 12 && $found[0]->score === 1.0);
$q = urldecode($requests[2]['url']);
check('lucene query quoted and escaped', str_contains($q, 'release:"OK \"Computer\"" AND artist:"Radiohead"'), $q);
$elapsed = (float) $clock->now()->format('U.u') - $t0;
check('throttled: 2 s backoff, then 1.05 s before the search', $elapsed >= 3.0, (string) $elapsed);

// ---- AcoustID ------------------------------------------------------------
$sent = null;
$acoustHttp = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
    $sent = ['method' => $method, 'body' => $options['body'] ?? ''];

    return new MockResponse(json_encode(['status' => 'ok', 'results' => [
        ['score' => 0.62, 'recordings' => [['id' => 'rec-x', 'title' => 'Other', 'artists' => [['name' => 'X']]]]],
        ['score' => 0.97, 'recordings' => [['id' => 'rec-7', 'title' => 'Fitter Happier', 'duration' => 117.3,
            'artists' => [['name' => 'Radiohead']], 'releases' => [['id' => 'rel-1', 'title' => 'OK Computer', 'track_count' => 12]]]]],
    ]]));
});
$acoust = new AcoustIdClient($acoustHttp, new MockClock(), 'KEY123');
$matches = $acoust->lookup(new Fingerprint('AQAA...', 117));
check('acoustid POST', $sent['method'] === 'POST');
parse_str(is_string($sent['body']) ? $sent['body'] : http_build_query($sent['body']), $body);
check('acoustid body has key, duration, meta', ($body['client'] ?? '') === 'KEY123' && ($body['duration'] ?? '') === '117' && ($body['meta'] ?? '') === 'recordings releases', json_encode($body));
check('acoustid best score first', ($matches[0]->id ?? '') === 'rec-7' && $matches[0]->score === 0.97);
check('acoustid release ids', ($matches[0]->releaseIds() ?? []) === ['rel-1']);
check('acoustid duration rounded', $matches[0]->duration === 117);
check('acoustid unconfigured → no call', (new AcoustIdClient($acoustHttp, new MockClock(), ''))->lookup(new Fingerprint('x', 1)) === []);

// ---- Cover Art Archive ----------------------------------------------------
$coverUrls = [];
$coverHttp = new MockHttpClient(static function (string $method, string $url) use (&$coverUrls): MockResponse {
    $coverUrls[] = $url;
    if (str_contains($url, '/release/')) {
        return new MockResponse('', ['http_code' => 404]);
    }

    return new MockResponse("\xFF\xD8\xFFjpeg", ['http_code' => 200, 'response_headers' => ['content-type' => 'image/jpeg']]);
});
$caa = new CoverArtClient($coverHttp, 'UA');
$img = $caa->front('rel-1', 'rg-1');
check('cover falls back to release group', $img === "\xFF\xD8\xFFjpeg" && \count($coverUrls) === 2 && str_contains($coverUrls[1], 'release-group/rg-1/front-500'));
check('no cover anywhere → null', (new CoverArtClient(new MockHttpClient(new MockResponse('', ['http_code' => 404])), 'UA'))->front('a') === null);

echo $failures === 0 ? "\nAll client tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
