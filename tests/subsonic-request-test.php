<?php

/*
 * Standalone test for SubsonicRequest: repeated parameters (which PHP's own
 * parsing collapses to the last value), GET + form POST, helpers.
 *
 * Run:  php tests/subsonic-request-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicRequest;
use Symfony\Component\HttpFoundation\Request;

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

$get = SubsonicRequest::fromRequest(Request::create('/rest/updatePlaylist.view?songIdToAdd=1&songIdToAdd=2&name=My+List&q=a%20b&f=json&c=Tempo'));
check('repeated GET params are all kept', $get->getAll('songIdToAdd') === ['1', '2'], json_encode($get->getAll('songIdToAdd')));
check('get() returns the first value', $get->get('songIdToAdd') === '1');
check('"+" decodes to a space', $get->get('name') === 'My List');
check('%20 decodes to a space', $get->get('q') === 'a b');
check('missing param → null / []', $get->get('nope') === null && $get->getAll('nope') === []);
check('f=json → json', $get->format() === 'json');
check('client name', $get->client() === 'Tempo');

$post = SubsonicRequest::fromRequest(Request::create(
    '/rest/updatePlaylist?f=json',
    'POST',
    server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
    content: 'songIdToAdd=3&songIdToAdd=4&playlistId=pl-9',
));
check('repeated POST form params are all kept', $post->getAll('songIdToAdd') === ['3', '4']);
check('query and body are merged', $post->get('playlistId') === 'pl-9' && $post->format() === 'json');

$json = SubsonicRequest::fromRequest(Request::create('/rest/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: 'songIdToAdd=5'));
check('non-form body is ignored', $json->get('songIdToAdd') === null);

$xml = SubsonicRequest::fromRequest(Request::create('/rest/ping'));
check('no f → xml', $xml->format() === 'xml');

$n = SubsonicRequest::fromRequest(Request::create('/rest/x?size=12&bad=abc&submission=true&off=false&neg=-3'));
check('int() parses', $n->int('size', 10) === 12);
check('int() default on garbage', $n->int('bad', 10) === 10);
check('int() default when missing', $n->int('missing', 7) === 7);
check('int() negative', $n->int('neg', 0) === -3);
check('bool() true', $n->bool('submission') === true);
check('bool() false / missing', $n->bool('off') === false && $n->bool('missing') === false);
check('bool() default', $n->bool('missing', true) === true);

try {
    $n->require('id');
    check('require() throws on missing', false);
} catch (SubsonicException $e) {
    check('require() throws code 10', $e->getCode() === 10);
}
check('require() returns value', $n->require('size') === '12');

try {
    SubsonicRequest::fromRequest(Request::create('/rest/x?id='))->require('id');
    check('require() throws on empty value', false);
} catch (SubsonicException $e) {
    check('require() throws on empty value', $e->getCode() === 10);
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
