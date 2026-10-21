<?php

/*
 * Standalone test for the LRCLIB client against canned responses: exact
 * lookup, search fallback and scoring, synced lyrics dropped on a duration
 * mismatch, instrumental, outage → exception.
 *
 * Run:  php tests/lyrics-lrclib-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Lyrics\LrclibClient;
use App\Service\Lyrics\LyricsException;
use App\Service\Spotify\SpotifyMatcher;
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
function client(array &$responses, array &$requests): LrclibClient
{
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
        $requests[] = ['url' => $url, 'options' => $options];

        return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
    });

    return new LrclibClient($http, new SpotifyMatcher(), 'SymfonyMusicTest/1.0 (test@example.com)', 0);
}

$record = static fn(array $over = []): string => json_encode($over + [
    'id' => 42,
    'trackName' => 'Paranoid Android',
    'artistName' => 'Radiohead',
    'albumName' => 'OK Computer',
    'duration' => 383.0,
    'instrumental' => false,
    'plainLyrics' => "Please could you stop the noise",
    'syncedLyrics' => "[00:01.50] Please could you stop the noise",
]);

echo "Exact lookup\n";
$requests = [];
$responses = [new MockResponse($record(), ['http_code' => 200])];
$match = client($responses, $requests)->find('Paranoid Android', 'Radiohead', 'OK Computer', 383);
check('found', $match !== null && $match->lrclibId === 42);
check('synced kept', $match?->synced === '[00:01.50] Please could you stop the noise');
check('text() prefers synced', $match?->text() === $match?->synced);
check('/api/get called with all fields', str_contains($requests[0]['url'], '/api/get?')
    && str_contains($requests[0]['url'], 'album_name=OK%20Computer') && str_contains($requests[0]['url'], 'duration=383'), $requests[0]['url']);
check('User-Agent sent', in_array('User-Agent: SymfonyMusicTest/1.0 (test@example.com)', $requests[0]['options']['headers'] ?? [], true));

echo "Search fallback (404 on exact lookup)\n";
$requests = [];
$responses = [
    new MockResponse('{"code":404,"name":"TrackNotFound"}', ['http_code' => 404]),
    new MockResponse('[' . implode(',', [
        $record(['id' => 1, 'trackName' => 'Karma Police', 'syncedLyrics' => '[00:01.00] wrong song']),
        $record(['id' => 2, 'syncedLyrics' => null]),
        $record(['id' => 3, 'duration' => 384.0]),
    ]) . ']', ['http_code' => 200]),
];
$match = client($responses, $requests)->find('Paranoid Android (Remastered)', 'Radiohead', 'OK Computer', 383);
check('picked the synced record of the right song', $match?->lrclibId === 3, (string) $match?->lrclibId);
check('search by cleaned title + artist', str_contains($requests[1]['url'] ?? '', '/api/search?track_name=Paranoid%20Android&artist_name=Radiohead'), $requests[1]['url'] ?? '');

echo "Unknown album → search only\n";
$requests = [];
$responses = [new MockResponse('[' . $record() . ']', ['http_code' => 200])];
$match = client($responses, $requests)->find('Paranoid Android', 'Radiohead', 'Unknown Album', 383);
check('one request, the search', count($requests) === 1 && str_contains($requests[0]['url'], '/api/search'));
check('found', $match?->lrclibId === 42);

echo "Duration mismatch → plain only\n";
$requests = [];
$responses = [new MockResponse('[' . $record(['duration' => 420.0]) . ']', ['http_code' => 200])];
$match = client($responses, $requests)->find('Paranoid Android', 'Radiohead', null, 383);
check('synced dropped (37 s apart)', $match !== null && $match->synced === null && $match->plain !== null);

echo "Unrelated results\n";
$requests = [];
$responses = [new MockResponse('[' . $record(['trackName' => 'Creep', 'artistName' => 'Stone Temple Pilots']) . ']', ['http_code' => 200])];
check('no match', client($responses, $requests)->find('Paranoid Android', 'Radiohead', null, 383) === null);

echo "Instrumental\n";
$requests = [];
$responses = [new MockResponse($record(['instrumental' => true, 'plainLyrics' => null, 'syncedLyrics' => null]), ['http_code' => 200])];
$match = client($responses, $requests)->find('Paranoid Android', 'Radiohead', 'OK Computer', 383);
check('instrumental, no text', $match !== null && $match->instrumental && $match->text() === null);

echo "503 on the exact lookup → search\n";
$requests = [];
$responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('[' . $record() . ']', ['http_code' => 200])];
$match = client($responses, $requests)->find('Paranoid Android', 'Radiohead', 'OK Computer', 383);
check('no retry on /api/get, found by search', $match?->lrclibId === 42 && count($requests) === 2);

echo "Outage\n";
$requests = [];
$responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503])];
try {
    client($responses, $requests)->find('Paranoid Android', 'Radiohead', null, 383);
    check('throws LyricsException', false);
} catch (LyricsException) {
    check('throws LyricsException after two retries', count($requests) === 3);
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
