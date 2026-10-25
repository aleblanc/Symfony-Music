<?php

/*
 * Standalone test for SubsonicResponse: the subsonic-response envelope in
 * JSON and XML (scalars → attributes, maps → child elements, lists →
 * repeated elements, "value" → text), and the error form.
 *
 * Run:  php tests/subsonic-response-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Subsonic\SubsonicResponse;

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

// ping, JSON
$r = SubsonicResponse::ok([], 'json');
$j = json_decode((string) $r->getContent(), true);
check('json: HTTP 200', $r->getStatusCode() === 200);
check('json: content type', str_starts_with((string) $r->headers->get('Content-Type'), 'application/json'));
check('json: envelope', ($j['subsonic-response']['status'] ?? null) === 'ok'
    && $j['subsonic-response']['version'] === '1.16.1'
    && $j['subsonic-response']['openSubsonic'] === true
    && $j['subsonic-response']['type'] === 'symfony-music');

// ping, XML
$r = SubsonicResponse::ok([], 'xml');
$x = simplexml_load_string((string) $r->getContent());
check('xml: content type', str_starts_with((string) $r->headers->get('Content-Type'), 'text/xml'));
check('xml: root + status', $x !== false && $x->getName() === 'subsonic-response' && (string) $x['status'] === 'ok');
check('xml: namespace', str_contains((string) $r->getContent(), 'xmlns="http://subsonic.org/restapi"'));
check('xml: openSubsonic is "true"', (string) $x['openSubsonic'] === 'true');

// Nested data
$payload = [
    'albumList2' => [
        'album' => [
            ['id' => 'al-1', 'name' => 'A & B', 'year' => 2001, 'starred' => null],
            ['id' => 'al-2', 'name' => 'C', 'isCompilation' => false],
        ],
    ],
];
$j = json_decode((string) SubsonicResponse::ok($payload, 'json')->getContent(), true);
check('json: list kept as array', \count($j['subsonic-response']['albumList2']['album']) === 2);
check('json: null fields dropped', !\array_key_exists('starred', $j['subsonic-response']['albumList2']['album'][0]));
check('json: ints stay ints', $j['subsonic-response']['albumList2']['album'][0]['year'] === 2001);

$xml = (string) SubsonicResponse::ok($payload, 'xml')->getContent();
$x = simplexml_load_string($xml);
check('xml: list → two repeated <album>', \count($x->albumList2->album) === 2, $xml);
check('xml: scalars → attributes (escaped)', (string) $x->albumList2->album[0]['name'] === 'A & B');
check('xml: null attribute omitted', !isset($x->albumList2->album[0]['starred']));
check('xml: false → "false"', (string) $x->albumList2->album[1]['isCompilation'] === 'false');

// "value" → text content (lyrics)
$x = simplexml_load_string((string) SubsonicResponse::ok(['lyrics' => ['artist' => 'X', 'value' => "line 1\nline 2"]], 'xml')->getContent());
check('xml: value → text', (string) $x->lyrics === "line 1\nline 2" && (string) $x->lyrics['artist'] === 'X');

// Empty object stays an object
$j = json_decode((string) SubsonicResponse::ok(['shares' => new \stdClass()], 'json')->getContent());
check('json: empty object is {}', $j->{'subsonic-response'}->shares instanceof \stdClass);
$x = simplexml_load_string((string) SubsonicResponse::ok(['shares' => new \stdClass()], 'xml')->getContent());
check('xml: empty object → empty element', isset($x->shares));

// Nested lists inside lists (structuredLyrics → line[])
$x = simplexml_load_string((string) SubsonicResponse::ok(['lyricsList' => ['structuredLyrics' => [
    ['synced' => true, 'line' => [['start' => 0, 'value' => 'a'], ['start' => 1500, 'value' => 'b']]],
]]], 'xml')->getContent());
check('xml: nested repeated elements', \count($x->lyricsList->structuredLyrics->line) === 2
    && (string) $x->lyricsList->structuredLyrics->line[1]['start'] === '1500'
    && (string) $x->lyricsList->structuredLyrics->line[1] === 'b');

// Errors
$r = SubsonicResponse::error(70, 'Album not found', 'xml');
$x = simplexml_load_string((string) $r->getContent());
check('error: HTTP 200', $r->getStatusCode() === 200);
check('error xml: failed + code', (string) $x['status'] === 'failed' && (string) $x->error['code'] === '70' && (string) $x->error['message'] === 'Album not found');
$j = json_decode((string) SubsonicResponse::error(10, 'Missing id', 'json')->getContent(), true);
check('error json', $j['subsonic-response']['status'] === 'failed' && $j['subsonic-response']['error'] === ['code' => 10, 'message' => 'Missing id']);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
