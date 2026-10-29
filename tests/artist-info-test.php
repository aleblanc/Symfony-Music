<?php

/*
 * Standalone test for the artist pages' data: ArtistInfoFetcher (MusicBrainz
 * → Wikidata → Wikipedia, homonyms told apart by the library's albums),
 * YouTubeClient (RSS feed, channel id from a page) and the "new videos" rule
 * of ArtistProfile, against canned responses.
 *
 * Run:  php tests/artist-info-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Entity\ArtistProfile;
use App\Service\Artist\ArtistInfoFetcher;
use App\Service\Artist\YouTubeClient;
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

$feed = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom">
 <entry><yt:videoId>old11111111</yt:videoId><title>Old one</title><published>2026-01-02T10:00:00+00:00</published></entry>
 <entry><yt:videoId>new22222222</yt:videoId><title> New single </title><published>2026-10-18T17:00:00+00:00</published></entry>
</feed>
XML;

// Two MusicBrainz artists named "Muse": the right one shares an album with the library.
$routes = [
    'musicbrainz.org/ws/2/artist?' => ['artists' => [
        ['id' => 'mb-other', 'name' => 'Muse', 'score' => 100, 'disambiguation' => 'Japanese band'],
        ['id' => 'mb-muse', 'name' => 'Muse', 'score' => 100, 'disambiguation' => 'UK rock band'],
        ['id' => 'mb-x', 'name' => 'Muse Muse', 'score' => 90],
    ]],
    // "Luke": the French band only shows up through its albums (a plain search ranks Laidback Luke first).
    'release-group?query=releasegroup%3A%22Les+Enfants+de+Saturne%22' => ['release-groups' => [
        ['title' => 'Les Enfants de Saturne', 'artist-credit' => [['artist' => ['id' => 'mb-luke-fr', 'name' => 'Luke', 'disambiguation' => 'French rock band']]]],
        ['title' => 'Saturn', 'artist-credit' => [['artist' => ['id' => 'mb-laidback', 'name' => 'Laidback Luke']]]],
    ]],
    'release-group?artist=mb-other' => ['release-groups' => [['title' => 'Something else']]],
    'release-group?artist=mb-muse' => ['release-groups' => [['title' => 'Origin of Symmetry'], ['title' => 'The Resistance']]],
    'artist/mb-muse?inc=url-rels' => ['relations' => [
        ['type' => 'wikidata', 'url' => ['resource' => 'https://www.wikidata.org/wiki/Q22151']],
        ['type' => 'youtube', 'url' => ['resource' => 'https://www.youtube.com/@muse']],
        ['type' => 'official homepage', 'url' => ['resource' => 'https://www.muse.mu/']],
        ['type' => 'free streaming', 'url' => ['resource' => 'https://open.spotify.com/artist/12Chz98pHFMPJEknJQMWvI']],
        ['type' => 'social network', 'url' => ['resource' => 'https://www.instagram.com/muse/'], 'ended' => true],
    ]],
    'Special:EntityData/Q22151.json' => ['entities' => ['Q22151' => [
        'descriptions' => ['fr' => ['value' => 'groupe de musique britannique']],
        'claims' => [
            'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Muse live 2019.jpg']]]],
            'P2397' => [['mainsnak' => ['datavalue' => ['value' => 'UCGGhM6XCSJFQ6DTRffnKRIw']]]],
        ],
        'sitelinks' => ['enwiki' => ['title' => 'Muse (band)'], 'frwiki' => ['title' => 'Muse (groupe)']],
    ]]],
    'fr.wikipedia.org/api/rest_v1/page/summary/Muse_%28groupe%29' => ['extract' => 'Muse est un groupe de rock britannique.', 'content_urls' => ['desktop' => ['page' => 'https://fr.wikipedia.org/wiki/Muse_(groupe)']]],
    'feeds/videos.xml?channel_id=UCGGhM6XCSJFQ6DTRffnKRIw' => $feed,
    'www.youtube.com/@someone' => '<html><link rel="canonical" href="https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv"></html>',
];
$requested = [];
$http = new MockHttpClient(static function (string $method, string $url) use ($routes, &$requested): MockResponse {
    $requested[] = $url;
    foreach ($routes as $needle => $body) {
        if (str_contains($url, $needle)) {
            return new MockResponse(\is_string($body) ? $body : json_encode($body), ['http_code' => 200]);
        }
    }

    return new MockResponse('', ['http_code' => 404]);
});

$clock = new MockClock('2026-10-20 12:00:00');
$mb = new MusicBrainzClient($http, new ArrayAdapter(), $clock, 'Test/1.0 ( test )');
$youtube = new YouTubeClient($http);
$fetcher = new ArtistInfoFetcher($mb, $youtube, $http, 'Test/1.0 ( test )');

$info = $fetcher->fetch('Muse', ['Origin of Symmetry', 'Absolution']);
check('homonym resolved by the library albums', ($info['mbid'] ?? null) === 'mb-muse', json_encode($info['mbid'] ?? null));
check('French description from Wikidata', ($info['description'] ?? null) === 'groupe de musique britannique');
check('photo from Wikimedia Commons', ($info['imageUrl'] ?? null) === 'https://commons.wikimedia.org/wiki/Special:FilePath/Muse_live_2019.jpg?width=600', (string) ($info['imageUrl'] ?? ''));
check('French Wikipedia article + summary', ($info['wikipediaUrl'] ?? null) === 'https://fr.wikipedia.org/wiki/Muse_(groupe)' && ($info['summary'] ?? null) === 'Muse est un groupe de rock britannique.', json_encode([$info['wikipediaUrl'] ?? null, $info['summary'] ?? null]));
check('YouTube channel from Wikidata, link from MusicBrainz', ($info['youtubeChannelId'] ?? null) === 'UCGGhM6XCSJFQ6DTRffnKRIw' && ($info['youtubeUrl'] ?? null) === 'https://www.youtube.com/@muse');
check('links: official first, ended ones dropped, MusicBrainz last', array_column($info['links'] ?? [], 'label') === ['Official site', 'Spotify', 'MusicBrainz'], json_encode(array_column($info['links'] ?? [], 'label')));
check('channel page not fetched when Wikidata has the id', !\in_array(true, array_map(static fn($u) => str_contains($u, '/@muse'), $requested), true));

$luke = $fetcher->pickArtist('Luke', ['Les Enfants de Saturne', 'Les Enfants de Saturne']);
check('homonym found through the release groups of the library albums', ($luke['id'] ?? null) === 'mb-luke-fr' && ($luke['disambiguation'] ?? null) === 'French rock band', json_encode($luke));

check('unknown artist → null', $fetcher->fetch('Nobody At All') === null);
check('name normalisation', ArtistInfoFetcher::normalize('The Beatles') === ArtistInfoFetcher::normalize('beatles') && ArtistInfoFetcher::normalize('Simon & Garfunkel') === 'simonandgarfunkel' && ArtistInfoFetcher::normalize('Mötley Crüe') === 'motleycrue');

check('channel id from a /channel/ URL', $youtube->resolveChannelId('https://www.youtube.com/channel/UCGGhM6XCSJFQ6DTRffnKRIw') === 'UCGGhM6XCSJFQ6DTRffnKRIw');
check('channel id from an @handle page', $youtube->resolveChannelId('https://www.youtube.com/@someone') === 'UCabcdefghijklmnopqrstuv');

$videos = $youtube->latestVideos('UCGGhM6XCSJFQ6DTRffnKRIw');
check('feed parsed, newest first, titles trimmed', array_column($videos ?? [], 'id') === ['new22222222', 'old11111111'] && ($videos[0]['title'] ?? '') === 'New single', json_encode($videos));
check('broken feed → null', YouTubeClient::parseFeed('<not xml') === null);

$profile = new ArtistProfile('Muse');
$profile->setVideos($videos ?? [], $clock->now());
check('never visited: only the last 30 days are new', array_column($profile->newVideos($clock->now()), 'id') === ['new22222222']);
$profile->markVideosSeen($clock->now());
check('after a visit: nothing new', $profile->newVideos($clock->now()) === []);
$clock->modify('+3 days');
$profile->setVideos([['id' => 'newer333333', 'title' => 'Newer', 'published' => '2026-10-22T09:00:00+00:00'], ...($videos ?? [])], $clock->now());
check('a video published after the visit is new', array_column($profile->newVideos($clock->now()), 'id') === ['newer333333']);

echo $failures === 0 ? "\nAll artist info tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
