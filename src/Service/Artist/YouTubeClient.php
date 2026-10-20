<?php

namespace App\Service\Artist;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * YouTube without an API key: the public RSS feed of a channel (its 15
 * latest videos) and, for links like youtube.com/@name or /user/name, the
 * channel id read from the channel page.
 */
class YouTubeClient
{
    private const CHANNEL_ID = '/^UC[\w-]{22}$/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function resolveChannelId(string $url): ?string
    {
        if (preg_match('~/channel/(UC[\w-]{22})~', $url, $m) === 1) {
            return $m[1];
        }
        $html = $this->get($url);
        if ($html === null) {
            return null;
        }

        return self::channelIdFromHtml($html);
    }

    public static function channelIdFromHtml(string $html): ?string
    {
        foreach ([
            '~<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[\w-]{22})"~',
            '~<meta itemprop="(?:channelId|identifier)" content="(UC[\w-]{22})"~',
            '~"externalId":"(UC[\w-]{22})"~',
            '~"channelId":"(UC[\w-]{22})"~',
        ] as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @return ?list<array{id: string, title: string, published: string}> newest first; null when the feed cannot be read
     */
    public function latestVideos(string $channelId): ?array
    {
        if (preg_match(self::CHANNEL_ID, $channelId) !== 1) {
            return null;
        }
        $xml = $this->get('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId);

        return $xml !== null ? self::parseFeed($xml) : null;
    }

    /**
     * @return ?list<array{id: string, title: string, published: string}>
     */
    public static function parseFeed(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($feed === false) {
            return null;
        }

        $videos = [];
        foreach ($feed->entry as $entry) {
            $id = (string) $entry->children('http://www.youtube.com/xml/schemas/2015')->videoId;
            $published = (string) $entry->published;
            if ($id === '' || $published === '') {
                continue;
            }
            try {
                $date = (new \DateTimeImmutable($published))->format(\DATE_ATOM);
            } catch (\Exception) {
                continue;
            }
            $videos[] = ['id' => $id, 'title' => trim((string) $entry->title), 'published' => $date];
        }
        usort($videos, static fn(array $a, array $b) => strcmp($b['published'], $a['published']));

        return $videos;
    }

    private function get(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                // Without these, European visitors get the cookie-consent page.
                'headers' => ['Cookie' => 'CONSENT=YES+1; SOCS=CAI', 'Accept-Language' => 'en', 'User-Agent' => 'Mozilla/5.0 (compatible; PHPMusic/1.0)'],
                'timeout' => 20,
                'max_duration' => 40,
            ]);

            return $response->getStatusCode() === 200 ? $response->getContent() : null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
