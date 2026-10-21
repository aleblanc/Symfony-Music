<?php

namespace App\Service\Artist;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Album popularity from ListenBrainz (MusicBrainz's open listening data):
 * total listens and distinct listeners per release group, every album of an
 * artist in one request, no API key.
 *
 * @see https://listenbrainz.readthedocs.io/en/latest/users/api/popularity.html
 */
class ListenBrainzClient
{
    private const URL = 'https://api.listenbrainz.org/1/popularity/release-group';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $musicbrainzUserAgent,
    ) {}

    /**
     * @param list<string> $releaseGroupIds MusicBrainz release group ids
     *
     * @return ?array<string, array{listens: int, listeners: int}> by id (unknown ones left out), null when ListenBrainz could not be read
     */
    public function releaseGroupPopularity(array $releaseGroupIds): ?array
    {
        if ($releaseGroupIds === []) {
            return [];
        }
        try {
            $rows = $this->httpClient->request('POST', self::URL, [
                'json' => ['release_group_mbids' => array_values($releaseGroupIds)],
                'headers' => ['User-Agent' => $this->musicbrainzUserAgent],
                'timeout' => 20,
            ])->toArray();
        } catch (ExceptionInterface) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            if (\is_array($row) && isset($row['release_group_mbid']) && ($row['total_listen_count'] ?? null) !== null) {
                $out[(string) $row['release_group_mbid']] = [
                    'listens' => (int) $row['total_listen_count'],
                    'listeners' => (int) ($row['total_user_count'] ?? 0),
                ];
            }
        }

        return $out;
    }
}
